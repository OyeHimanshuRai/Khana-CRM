<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ProductStock;
use App\Models\Warehouse;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Warehouses: where a shop keeps its stock.
 *
 * Shop-scoped through BelongsToShop, so the listing already shows only what
 * the reader may see. In All-shops mode the list spans their shops and each
 * row names its own, which is the only way the codes make sense together.
 */
class WarehouseController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $warehouses = $this->filtered($request)
            ->with('shop:id,name,code')
            ->withCount('stocks')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'warehouses' => $warehouses,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'total' => Warehouse::count(),
                'active' => Warehouse::where('is_active', true)->count(),
                'inactive' => Warehouse::where('is_active', false)->count(),
                'stocked' => ProductStock::where('quantity', '>', 0)
                    ->distinct('warehouse_id')
                    ->count('warehouse_id'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.warehouses._list', $data)
            : view('admin.warehouses.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Warehouse::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.warehouses._form', [
            'warehouse' => new Warehouse(),
            'shops' => CurrentShop::accessible(),
        ]);
    }

    public function edit(Warehouse $warehouse): View
    {
        return view('admin.warehouses._form', [
            'warehouse' => $warehouse,
            'shops' => CurrentShop::accessible(),
        ]);
    }

    public function show(Warehouse $warehouse): View
    {
        return view('admin.warehouses._show', [
            'warehouse' => $warehouse->load('shop'),
            'stockedLines' => ProductStock::where('warehouse_id', $warehouse->id)
                ->where('quantity', '>', 0)
                ->count(),
            'stockValue' => (float) ProductStock::where('warehouse_id', $warehouse->id)
                ->selectRaw('COALESCE(SUM(quantity * average_cost), 0) as total')
                ->value('total'),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $warehouse = new Warehouse($this->attributes($data));
        $warehouse->save();

        // Done after the save so the demotion cannot fire for a row that
        // then fails to insert.
        if ($warehouse->is_default) {
            $warehouse->makeDefault();
        }

        ActivityLog::record('warehouse.created', "Created warehouse \"{$warehouse->name}\"", $warehouse);

        return ApiResponse::success("Warehouse \"{$warehouse->name}\" created.", $this->payload($warehouse));
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $data = $this->validated($request, $warehouse);

        // The shop is fixed once stock has been filed against it: moving a
        // warehouse between shops would move that stock too, silently.
        $attributes = $this->attributes($data);
        unset($attributes['shop_id']);

        $warehouse->fill($attributes)->save();

        if ($warehouse->is_default) {
            $warehouse->makeDefault();
        }

        ActivityLog::record('warehouse.updated', "Updated warehouse \"{$warehouse->name}\"", $warehouse);

        return ApiResponse::success("Warehouse \"{$warehouse->name}\" updated.", $this->payload($warehouse));
    }

    /**
     * Remove a warehouse.
     *
     * Refused while it holds stock, and refused when it is the shop's last
     * one - both would leave quantities with nowhere to live.
     */
    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $held = ProductStock::where('warehouse_id', $warehouse->id)
            ->where('quantity', '!=', 0)
            ->count();

        if ($held > 0) {
            return ApiResponse::error(sprintf(
                '"%s" still holds stock in %d product line%s. Transfer or adjust those out first.',
                $warehouse->name,
                $held,
                $held === 1 ? '' : 's',
            ));
        }

        $siblings = Warehouse::allShops()
            ->where('shop_id', $warehouse->shop_id)
            ->where('id', '!=', $warehouse->id)
            ->count();

        if ($siblings === 0) {
            return ApiResponse::error('This is the shop\'s only warehouse. Create another before removing it.');
        }

        $name = $warehouse->name;
        $wasDefault = $warehouse->is_default;
        $shopId = $warehouse->shop_id;

        $warehouse->delete();

        // The shop must always have somewhere stock lands by default.
        if ($wasDefault) {
            Warehouse::allShops()
                ->where('shop_id', $shopId)
                ->orderBy('sort_order')
                ->first()
                ?->makeDefault();
        }

        ActivityLog::record('warehouse.deleted', "Deleted warehouse \"{$name}\"");

        return ApiResponse::success("Warehouse \"{$name}\" deleted.");
    }

    public function toggleStatus(Warehouse $warehouse): JsonResponse
    {
        $active = ! $warehouse->is_active;

        if (! $active && $warehouse->is_default) {
            return ApiResponse::error('The default warehouse has to stay active. Make another one the default first.');
        }

        $warehouse->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'warehouse.activated' : 'warehouse.deactivated',
            ($active ? 'Activated' : 'Deactivated')." warehouse \"{$warehouse->name}\"",
            $warehouse,
        );

        return ApiResponse::success(
            "\"{$warehouse->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /** Make this the shop's default without opening the edit form. */
    public function makeDefault(Warehouse $warehouse): JsonResponse
    {
        if (! $warehouse->is_active) {
            return ApiResponse::error('Activate the warehouse before making it the default.');
        }

        $warehouse->makeDefault();

        ActivityLog::record(
            'warehouse.default_changed',
            "Made \"{$warehouse->name}\" the default warehouse",
            $warehouse,
        );

        return ApiResponse::success("Stock now lands in \"{$warehouse->name}\" by default.");
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Warehouse $warehouse = null): array
    {
        // Which shop the code has to be unique within: the row's own on an
        // edit, the submitted one on a create.
        $shopId = $warehouse?->shop_id
            ?? (int) $request->integer('shop_id', (int) CurrentShop::idForWrite());

        return $request->validate([
            'shop_id' => [
                $warehouse ? 'nullable' : 'required',
                'integer',
                Rule::in(CurrentShop::accessibleIds()),
            ],
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:20',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/',
                Rule::unique('warehouses', 'code')
                    ->where('shop_id', $shopId)
                    ->ignore($warehouse?->id),
            ],
            'address' => ['nullable', 'string', 'max:250'],
            'city' => ['nullable', 'string', 'max:90'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ], [
            'shop_id.in' => 'You do not have access to that shop.',
            'shop_id.required' => 'Choose which shop this warehouse belongs to.',
            'code.regex' => 'Use letters, numbers, hyphens or underscores; start with a letter or number.',
            'code.unique' => 'That code is already used by another warehouse in this shop.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'shop_id' => $data['shop_id'] ?? CurrentShop::idForWrite(),
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'code' => $warehouse->code,
            'is_default' => $warehouse->is_default,
            'is_active' => $warehouse->is_active,
        ];
    }
}
