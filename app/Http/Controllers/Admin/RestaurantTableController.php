<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Floor;
use App\Models\RestaurantTable;
use App\Services\TableQrService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Tables: the list, the floor plan, and the status every other screen reads.
 *
 * Two views of the same rows. The list is where tables are created and
 * edited; the plan (§7) is where the front of house works, and it is the one
 * that has to answer "what is free right now" at a glance.
 *
 * A table gets its QR the moment it is created. §3.2 wants a code per table
 * and nothing about the flow is improved by a second step somebody can
 * forget - regenerating is the deliberate act, issuing is not.
 */
class RestaurantTableController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function __construct(private readonly TableQrService $qrs) {}

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $tables = $this->filtered($request)
            ->with(['floor:id,name,code', 'shop:id,name,code', 'activeQr', 'currentSession'])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'tables' => $tables,
            'floors' => Floor::active()->orderBy('sort_order')->orderBy('name')->get(),
            'search' => $request->string('q')->toString(),
            'floorId' => $request->integer('floor_id') ?: null,
            'status' => $request->string('status')->toString(),
            'statuses' => RestaurantTable::STATUSES,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'showsShop' => CurrentShop::id() === null,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.tables._list', $data)
            : view('admin.tables.index', $data);
    }

    /**
     * The floor plan (§7).
     *
     * One floor at a time. A restaurant with a rooftop and a basement has no
     * single canvas they both sit on, and drawing them together would mean
     * inventing a spatial relationship that does not exist.
     */
    public function plan(Request $request): View
    {
        $floors = Floor::active()->orderBy('sort_order')->orderBy('name')->get();

        $floor = $request->integer('floor_id')
            ? $floors->firstWhere('id', $request->integer('floor_id'))
            : $floors->first();

        $tables = $floor
            ? RestaurantTable::query()
                ->where('floor_id', $floor->id)
                ->active()
                ->with(['activeQr', 'currentSession'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
            : collect();

        $data = [
            'floors' => $floors,
            'floor' => $floor,
            'tables' => $tables,
            'statuses' => RestaurantTable::STATUSES,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.tables._plan', $data)
            : view('admin.tables.plan', $data);
    }

    private function filtered(Request $request): Builder
    {
        return RestaurantTable::query()
            ->search($request->string('q')->toString())
            ->when($request->integer('floor_id'), fn (Builder $q, int $id) => $q->where('floor_id', $id))
            ->ofStatus($request->string('status')->toString())
            ->when($request->string('active')->toString(), function (Builder $query, string $active) {
                $query->where('is_active', $active === 'yes');
            })
            ->orderBy('floor_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * The five counts §5 asks the dashboard for, plus the totals around them.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $byStatus = RestaurantTable::query()
            ->active()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stats = ['total' => (int) $byStatus->sum()];

        foreach (array_keys(RestaurantTable::STATUSES) as $status) {
            $stats[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $stats['seats'] = (int) RestaurantTable::query()->active()->sum('capacity');
        $stats['unassigned'] = RestaurantTable::query()
            ->active()
            ->whereDoesntHave('qrs', fn (Builder $q) => $q->whereNull('revoked_at'))
            ->count();

        return $stats;
    }

    /* ------------------------------------------------------ modal screens */

    public function create(Request $request): View
    {
        $floors = Floor::active()->orderBy('sort_order')->orderBy('name')->get();

        $floor = $request->integer('floor_id')
            ? $floors->firstWhere('id', $request->integer('floor_id'))
            : $floors->first();

        return view('admin.tables._form', [
            'table' => new RestaurantTable([
                'floor_id' => $floor?->id,
                'capacity' => 4,
                'status' => RestaurantTable::AVAILABLE,
                'is_active' => true,
                // Offered, not imposed - the field stays editable.
                'code' => $floor ? RestaurantTable::nextCode($floor) : null,
            ]),
            'floors' => $floors,
            'statuses' => RestaurantTable::STATUSES,
        ]);
    }

    public function edit(RestaurantTable $table): View
    {
        return view('admin.tables._form', [
            'table' => $table,
            'floors' => Floor::orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => RestaurantTable::STATUSES,
        ]);
    }

    public function show(RestaurantTable $table): View
    {
        return view('admin.tables._show', [
            'table' => $table->load(['floor', 'shop', 'activeQr', 'currentSession', 'qrs.issuer']),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $table = new RestaurantTable($this->attributes($data));
        $table->save();

        // Issued here rather than on first use: a table without a code is a
        // table nobody can order from, and that is not a state worth having.
        $this->qrs->issue($table, 'Issued with the table');

        ActivityLog::record('table.created', "Created table {$table->code}", $table);

        return ApiResponse::success("Table {$table->name} created.", $this->payload($table));
    }

    public function update(Request $request, RestaurantTable $table): JsonResponse
    {
        $data = $this->validated($request, $table);

        $attributes = $this->attributes($data);
        // The shop is fixed: its QR codes and its order history live there.
        unset($attributes['shop_id']);

        $table->fill($attributes)->save();

        ActivityLog::record('table.updated', "Updated table {$table->code}", $table);

        return ApiResponse::success("Table {$table->name} updated.", $this->payload($table));
    }

    /**
     * Remove a table.
     *
     * Refused while anybody is sitting at it. Deactivating is the usual
     * answer - it keeps the code, the history and the sticker.
     */
    public function destroy(RestaurantTable $table): JsonResponse
    {
        if ($table->isSeated()) {
            return ApiResponse::error(
                "Table {$table->name} is still {$table->statusLabel()}. Close it out before removing it."
            );
        }

        $name = $table->name;
        $code = $table->code;

        // Codes go with it. Nothing else can resolve them once the table is
        // gone, and leaving them live would mean a scan with no destination.
        $this->qrs->revoke($table, 'Table removed');

        $table->delete();

        ActivityLog::record('table.deleted', "Deleted table {$code}");

        return ApiResponse::success("Table {$name} deleted.");
    }

    public function toggleStatus(RestaurantTable $table): JsonResponse
    {
        $active = ! $table->is_active;

        if (! $active && $table->isSeated()) {
            return ApiResponse::error(
                "Table {$table->name} is {$table->statusLabel()}. Close it out before taking it out of service."
            );
        }

        $table->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'table.activated' : 'table.deactivated',
            ($active ? 'Activated' : 'Deactivated')." table {$table->code}",
            $table,
        );

        return ApiResponse::success(
            "Table {$table->name} is now ".($active ? 'in service' : 'out of service').'.',
            ['is_active' => $active],
        );
    }

    /**
     * Set the state of the room (§5, §7).
     *
     * The one write the floor plan makes, and the one the front of house uses
     * all evening: seat a party, mark a table for clearing, hold it for a
     * booking. Deliberately not derived from the order - see the migration.
     */
    public function setStatus(Request $request, RestaurantTable $table): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(RestaurantTable::STATUSES))],
            'note' => ['nullable', 'string', 'max:250'],
        ]);

        /*
         | A table with an open sitting cannot be marked free.
         |
         | The flag and the sitting are two different things, and this is the
         | one direction where letting them disagree does damage: the plan
         | would offer a table that a party is still eating at, and the next
         | guests would be walked to it. Freeing a table means ending the
         | sitting - settle the bill, or write it off - and both of those live
         | on Table Bills where the money is.
         */
        $sitting = $table->currentSession;

        if ($sitting !== null
            && in_array($data['status'], [RestaurantTable::AVAILABLE, RestaurantTable::CLEANING, RestaurantTable::RESERVED], true)) {
            return ApiResponse::error(sprintf(
                '%s is still sitting at table %s with the bill open. Settle or close that sitting first — '
                .'marking the table free would offer it to the next party while they are still at it.',
                $sitting->partyName(),
                $table->name,
            ));
        }

        $from = $table->statusLabel();

        $table->forceFill([
            'status' => $data['status'],
            // A note belongs to the state it was written for: "booked 8pm"
            // must not survive the party arriving.
            'note' => $data['note'] ?? null,
        ])->save();

        ActivityLog::record(
            'table.status',
            "Table {$table->code}: {$from} → {$table->statusLabel()}",
            $table,
        );

        return ApiResponse::success("Table {$table->name} is now {$table->statusLabel()}.", [
            'status' => $table->status,
            'label' => $table->statusLabel(),
            'tone' => $table->statusTone(),
        ]);
    }

    /**
     * Save where a table sits on the plan.
     *
     * Percentages of the canvas, clamped here as well as in the browser: the
     * drag handler is the usual caller but it is not the only possible one,
     * and a table at 4000% would simply vanish off the plan with no way back
     * except the database.
     */
    public function setPosition(Request $request, RestaurantTable $table): JsonResponse
    {
        $data = $request->validate([
            'pos_x' => ['required', 'numeric', 'between:0,100'],
            'pos_y' => ['required', 'numeric', 'between:0,100'],
        ]);

        $table->forceFill([
            'pos_x' => round((float) $data['pos_x'], 3),
            'pos_y' => round((float) $data['pos_y'], 3),
        ])->save();

        // No activity log. A hundred of these land while somebody arranges a
        // plan, and an audit trail of nudges buries the entries that matter.
        return ApiResponse::success('Position saved.', [
            'pos_x' => (float) $table->pos_x,
            'pos_y' => (float) $table->pos_y,
        ]);
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?RestaurantTable $table = null): array
    {
        $shopId = $table?->shop_id
            ?? (int) $request->integer('shop_id', (int) CurrentShop::idForWrite());

        return $request->validate([
            'shop_id' => [
                $table ? 'nullable' : 'required',
                'integer',
                Rule::in(CurrentShop::accessibleIds()),
            ],
            'floor_id' => [
                'required', 'integer',
                // Scoped to the branch, so a hand-edited form cannot file a
                // table onto another restaurant's rooftop.
                Rule::exists('floors', 'id')->where('shop_id', $shopId),
            ],
            'name' => [
                'required', 'string', 'max:40',
                Rule::unique('restaurant_tables', 'name')
                    ->where('floor_id', (int) $request->integer('floor_id'))
                    ->ignore($table?->id),
            ],
            'code' => [
                'required', 'string', 'max:24', 'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('restaurant_tables', 'code')
                    ->where('shop_id', $shopId)
                    ->ignore($table?->id),
            ],
            'capacity' => ['required', 'integer', 'between:1,60'],
            'status' => ['nullable', 'string', Rule::in(array_keys(RestaurantTable::STATUSES))],
            'note' => ['nullable', 'string', 'max:250'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ], [
            'shop_id.in' => 'You do not have access to that shop.',
            'floor_id.exists' => 'Choose a dining area in this shop.',
            'name.unique' => 'That area already has a table with this name.',
            'code.regex' => 'Use letters, numbers or hyphens only.',
            'code.unique' => 'That code is already used by another table in this shop.',
            'capacity.between' => 'A table seats between 1 and 60.',
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
            'floor_id' => (int) $data['floor_id'],
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'capacity' => (int) $data['capacity'],
            'status' => $data['status'] ?? RestaurantTable::AVAILABLE,
            'note' => $data['note'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RestaurantTable $table): array
    {
        return [
            'id' => $table->id,
            'name' => $table->name,
            'code' => $table->code,
            'status' => $table->status,
            'qr_url' => $table->activeQr?->url(),
        ];
    }
}
