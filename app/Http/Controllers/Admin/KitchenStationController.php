<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\OrderItem;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Kitchen stations: Bar, Main Kitchen, Tandoor, Bakery, Dessert (§9).
 *
 * Set up once when the outlet is configured, which is why this is a plain
 * fragment/modal CRUD and the interesting behaviour all lives on the KDS.
 *
 * The one rule with teeth is the default station: exactly one per branch, and
 * it cannot be deleted or switched off while it is the only thing standing
 * between an unrouted dish and nobody cooking it.
 */
class KitchenStationController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $stations = $this->filtered($request)
            ->with('shop:id,name,code')
            ->withCount(['categories', 'products'])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'stations' => $stations,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.kitchen-stations._list', $data)
            : view('admin.kitchen-stations.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return KitchenStation::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        return [
            'total' => KitchenStation::count(),
            'active' => KitchenStation::where('is_active', true)->count(),
            /*
             | Dishes that no station claims and no category routes.
             |
             | They are not lost - the router drops them on the default
             | station - but they are the rows somebody meant to file, so the
             | number is worth a tile. Read against active categories only:
             | a dish under a switched-off section is unrouted in practice.
             */
            'unrouted' => Product::query()
                ->where('is_active', true)
                ->whereNull('kitchen_station_id')
                ->where(fn (Builder $q) => $q
                    ->whereNull('category_id')
                    ->orWhereHas('category', fn (Builder $c) => $c
                        ->whereNull('kitchen_station_id')
                        ->where(fn (Builder $p) => $p
                            ->whereNull('parent_id')
                            ->orWhereDoesntHave('parent', fn (Builder $pp) => $pp->whereNotNull('kitchen_station_id')))))
                ->count(),
        ];
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.kitchen-stations._form', [
            'station' => new KitchenStation([
                'is_active' => true,
                'prep_minutes' => 15,
                // The first station a branch creates is its default: a shop
                // with stations and no default is a shop where an unrouted
                // dish is cooked by nobody.
                'is_default' => KitchenStation::query()->count() === 0,
            ]),
            'shops' => CurrentShop::accessible(),
        ]);
    }

    public function edit(KitchenStation $station): View
    {
        return view('admin.kitchen-stations._form', [
            'station' => $station,
            'shops' => CurrentShop::accessible(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $station = new KitchenStation($this->attributes($data));
        $station->is_default = false;
        $station->save();

        // First in the branch, or asked for: either way through the one
        // method that can hold "exactly one default" under a race.
        if (($data['is_default'] ?? false) || KitchenStation::query()->count() === 1) {
            $station->makeDefault();
        }

        ActivityLog::record('kitchen_station.created', "Created kitchen station \"{$station->name}\"", $station);

        return ApiResponse::success("Station \"{$station->name}\" created.", $this->payload($station));
    }

    public function update(Request $request, KitchenStation $station): JsonResponse
    {
        $data = $this->validated($request, $station);

        $attributes = $this->attributes($data);
        // Fixed: its routing and its ticket history live in one branch.
        unset($attributes['shop_id'], $attributes['is_default']);

        $station->fill($attributes)->save();

        if ($data['is_default'] ?? false) {
            $station->makeDefault();
        }

        ActivityLog::record('kitchen_station.updated', "Updated kitchen station \"{$station->name}\"", $station);

        return ApiResponse::success("Station \"{$station->name}\" updated.", $this->payload($station->refresh()));
    }

    /**
     * Remove a station.
     *
     * Refused while anything is still routed to it, and refused for the
     * default while another station exists to inherit the job. Both would
     * otherwise be a quiet re-route of the menu, discovered on a Friday.
     *
     * Tickets already cooked keep their line: order_items.kitchen_station_id
     * is nullOnDelete, so the history reads "—" rather than disappearing.
     */
    public function destroy(KitchenStation $station): JsonResponse
    {
        $routed = $station->categories()->count() + $station->products()->count();

        if ($routed > 0) {
            return ApiResponse::error(sprintf(
                '"%s" still has %d item%s routed to it. Move them first.',
                $station->name,
                $routed,
                $routed === 1 ? '' : 's',
            ));
        }

        $outstanding = OrderItem::query()
            ->outstanding()
            ->where('kitchen_station_id', $station->id)
            ->count();

        if ($outstanding > 0) {
            return ApiResponse::error(sprintf(
                '"%s" is still cooking %d line%s. Clear the board first.',
                $station->name,
                $outstanding,
                $outstanding === 1 ? '' : 's',
            ));
        }

        if ($station->is_default && KitchenStation::query()->whereKeyNot($station->id)->exists()) {
            return ApiResponse::error(
                "\"{$station->name}\" is the default station. Make another one the default first."
            );
        }

        $name = $station->name;
        $station->delete();

        ActivityLog::record('kitchen_station.deleted', "Deleted kitchen station \"{$name}\"");

        return ApiResponse::success("Station \"{$name}\" deleted.");
    }

    /** Switch a station on or off - a section closed for the season. */
    public function toggleStatus(KitchenStation $station): JsonResponse
    {
        $active = ! $station->is_active;

        if (! $active) {
            $outstanding = OrderItem::query()
                ->outstanding()
                ->where('kitchen_station_id', $station->id)
                ->count();

            if ($outstanding > 0) {
                return ApiResponse::error(sprintf(
                    '"%s" still has %d line%s on the board. Clear them first.',
                    $station->name,
                    $outstanding,
                    $outstanding === 1 ? '' : 's',
                ));
            }

            if ($station->is_default && KitchenStation::query()->active()->whereKeyNot($station->id)->exists()) {
                return ApiResponse::error(
                    "\"{$station->name}\" is the default station. Make another one the default first."
                );
            }
        }

        $station->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'kitchen_station.activated' : 'kitchen_station.deactivated',
            ($active ? 'Activated' : 'Deactivated')." kitchen station \"{$station->name}\"",
            $station,
        );

        return ApiResponse::success(
            "\"{$station->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /** Make this the branch's default. Its own route, because it is one tap. */
    public function makeDefault(KitchenStation $station): JsonResponse
    {
        if (! $station->is_active) {
            return ApiResponse::error(
                "\"{$station->name}\" is switched off. Turn it back on before making it the default."
            );
        }

        $station->makeDefault();

        ActivityLog::record(
            'kitchen_station.default',
            "\"{$station->name}\" is now the default kitchen station",
            $station,
        );

        return ApiResponse::success("\"{$station->name}\" is now the default station.");
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?KitchenStation $station = null): array
    {
        $shopId = $station?->shop_id
            ?? (int) $request->integer('shop_id', (int) CurrentShop::idForWrite());

        return $request->validate([
            'shop_id' => [
                $station ? 'nullable' : 'required',
                'integer',
                Rule::in(CurrentShop::accessibleIds()),
            ],
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('kitchen_stations', 'name')
                    ->where('shop_id', $shopId)
                    ->ignore($station?->id),
            ],
            'code' => [
                'required', 'string', 'max:12', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('kitchen_stations', 'code')
                    ->where('shop_id', $shopId)
                    ->ignore($station?->id),
            ],
            'description' => ['nullable', 'string', 'max:250'],
            'prep_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'shop_id.in' => 'You do not have access to that shop.',
            'shop_id.required' => 'Choose which shop this station belongs to.',
            'code.regex' => 'Use letters, numbers or hyphens only.',
            'code.unique' => 'That code is already used by another station in this shop.',
            'name.unique' => 'This shop already has a station with that name.',
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
            // Upper-cased on the way in: it is printed on a KOT slip beside
            // the table code, and "tan" next to "GF-04" reads as two schemes.
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'prep_minutes' => (int) ($data['prep_minutes'] ?? 15) ?: 15,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(KitchenStation $station): array
    {
        return [
            'id' => $station->id,
            'name' => $station->name,
            'code' => $station->code,
            'is_default' => $station->is_default,
            'is_active' => $station->is_active,
        ];
    }
}
