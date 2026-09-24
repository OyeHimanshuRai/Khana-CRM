<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Floor;
use App\Models\RestaurantTable;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dining areas: Ground Floor, Rooftop, AC Hall, Garden.
 *
 * Same fragment/modal shape as the rest of the admin. Shop-scoped through
 * BelongsToShop, so the listing already shows only what the reader may see.
 */
class FloorController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $floors = $this->filtered($request)
            ->with('shop:id,name,code')
            ->withCount([
                'tables',
                'tables as active_tables_count' => fn (Builder $q) => $q->where('is_active', true),
            ])
            ->withSum(['tables as seats' => fn (Builder $q) => $q->where('is_active', true)], 'capacity')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'floors' => $floors,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => [
                'total' => Floor::count(),
                'active' => Floor::where('is_active', true)->count(),
                'tables' => RestaurantTable::count(),
                'seats' => (int) RestaurantTable::where('is_active', true)->sum('capacity'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.floors._list', $data)
            : view('admin.floors.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Floor::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.floors._form', [
            'floor' => new Floor(['is_active' => true]),
            'shops' => CurrentShop::accessible(),
        ]);
    }

    public function edit(Floor $floor): View
    {
        return view('admin.floors._form', [
            'floor' => $floor,
            'shops' => CurrentShop::accessible(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $floor = new Floor($this->attributes($data));
        $floor->save();

        ActivityLog::record('floor.created', "Created dining area \"{$floor->name}\"", $floor);

        return ApiResponse::success("Dining area \"{$floor->name}\" created.", $this->payload($floor));
    }

    public function update(Request $request, Floor $floor): JsonResponse
    {
        $data = $this->validated($request, $floor);

        // The shop is fixed once tables hang off it: moving the area between
        // branches would move their tables, their QR codes and their history.
        $attributes = $this->attributes($data);
        unset($attributes['shop_id']);

        $floor->fill($attributes)->save();

        ActivityLog::record('floor.updated', "Updated dining area \"{$floor->name}\"", $floor);

        return ApiResponse::success("Dining area \"{$floor->name}\" updated.", $this->payload($floor));
    }

    /**
     * Remove a dining area.
     *
     * Refused while it still has tables. Cascading would take live QR codes
     * and the order history behind them, and "delete the rooftop" should
     * never be able to mean that by accident.
     */
    public function destroy(Floor $floor): JsonResponse
    {
        $tables = $floor->tables()->count();

        if ($tables > 0) {
            return ApiResponse::error(sprintf(
                '"%s" still has %d table%s. Move or remove them first.',
                $floor->name,
                $tables,
                $tables === 1 ? '' : 's',
            ));
        }

        $name = $floor->name;
        $floor->delete();

        ActivityLog::record('floor.deleted', "Deleted dining area \"{$name}\"");

        return ApiResponse::success("Dining area \"{$name}\" deleted.");
    }

    /**
     * Switch an area on or off.
     *
     * Turning it off hides it from the POS and the floor plan and keeps its
     * tables and their codes intact - a rooftop closed for the monsoon comes
     * back with the same stickers on the same tables.
     */
    public function toggleStatus(Floor $floor): JsonResponse
    {
        $active = ! $floor->is_active;

        if (! $active) {
            $seated = $floor->tables()
                ->whereIn('status', [RestaurantTable::OCCUPIED, RestaurantTable::BILLING])
                ->count();

            if ($seated > 0) {
                return ApiResponse::error(sprintf(
                    '%d table%s on "%s" still seated. Close those out first.',
                    $seated,
                    $seated === 1 ? ' is' : 's are',
                    $floor->name,
                ));
            }
        }

        $floor->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'floor.activated' : 'floor.deactivated',
            ($active ? 'Activated' : 'Deactivated')." dining area \"{$floor->name}\"",
            $floor,
        );

        return ApiResponse::success(
            "\"{$floor->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Floor $floor = null): array
    {
        // Which shop the name and code have to be unique within: the row's
        // own on an edit, the submitted one on a create.
        $shopId = $floor?->shop_id
            ?? (int) $request->integer('shop_id', (int) CurrentShop::idForWrite());

        return $request->validate([
            'shop_id' => [
                $floor ? 'nullable' : 'required',
                'integer',
                Rule::in(CurrentShop::accessibleIds()),
            ],
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('floors', 'name')
                    ->where('shop_id', $shopId)
                    ->ignore($floor?->id),
            ],
            'code' => [
                'required', 'string', 'max:12', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('floors', 'code')
                    ->where('shop_id', $shopId)
                    ->ignore($floor?->id),
            ],
            'description' => ['nullable', 'string', 'max:250'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'shop_id.in' => 'You do not have access to that shop.',
            'shop_id.required' => 'Choose which shop this dining area belongs to.',
            'code.regex' => 'Use letters, numbers or hyphens only.',
            'code.unique' => 'That code is already used by another area in this shop.',
            'name.unique' => 'This shop already has an area with that name.',
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
            // Upper-cased on the way in, because it is printed on a KOT
            // alongside the table code and "gf" next to "GF-04" reads as two
            // different schemes.
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Floor $floor): array
    {
        return [
            'id' => $floor->id,
            'name' => $floor->name,
            'code' => $floor->code,
            'is_active' => $floor->is_active,
        ];
    }
}
