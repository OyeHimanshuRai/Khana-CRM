<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Unit;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Units of measure.
 *
 * A small master, but not a trivial one: allow_decimal is what stops the
 * POS accepting "2.5 sprayers", so the form spells out the consequence
 * rather than presenting it as a checkbox with no context.
 */
class UnitController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $units = $this->filtered($request)
            ->withCount('products')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'units' => $units,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Unit::count(),
                'active' => Unit::where('is_active', true)->count(),
                'decimal' => Unit::where('allow_decimal', true)->count(),
                'whole' => Unit::where('allow_decimal', false)->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.units._list', $data)
            : view('admin.units.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Unit::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'name_asc' => $query->orderBy('name'),
                    'name_desc' => $query->orderByDesc('name'),
                    'newest' => $query->latest(),
                    default => $query->orderBy('sort_order')->orderBy('name'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.units._form', ['unit' => new Unit()]);
    }

    public function edit(Unit $unit): View
    {
        return view('admin.units._form', ['unit' => $unit]);
    }

    public function show(Unit $unit): View
    {
        return view('admin.units._show', ['unit' => $unit->loadCount('products')]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $unit = Unit::create($this->attributes($data));

        ActivityLog::record('unit.created', "Created unit \"{$unit->code}\"", $unit);

        return ApiResponse::success("Unit \"{$unit->code}\" created.", $this->payload($unit));
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $data = $this->validated($request, $unit);

        $unit->fill($this->attributes($data))->save();

        ActivityLog::record('unit.updated', "Updated unit \"{$unit->code}\"", $unit);

        return ApiResponse::success("Unit \"{$unit->code}\" updated.", $this->payload($unit));
    }

    /**
     * Remove a unit.
     *
     * Refused while products still measure themselves in it: a product with
     * no unit cannot be sold, so nulling the link would take stock off the
     * shelf by accident.
     */
    public function destroy(Unit $unit): JsonResponse
    {
        $inUse = $unit->products()->withTrashed()->count();

        if ($inUse > 0) {
            return ApiResponse::error(sprintf(
                '"%s" is used by %d product%s. Change those to another unit first.',
                $unit->code,
                $inUse,
                $inUse === 1 ? '' : 's',
            ));
        }

        $code = $unit->code;
        $unit->delete();

        ActivityLog::record('unit.deleted', "Deleted unit \"{$code}\"");

        return ApiResponse::success("Unit \"{$code}\" deleted.");
    }

    public function toggleStatus(Unit $unit): JsonResponse
    {
        $active = ! $unit->is_active;

        $unit->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'unit.activated' : 'unit.deactivated',
            ($active ? 'Activated' : 'Deactivated')." unit \"{$unit->code}\"",
            $unit,
        );

        return ApiResponse::success(
            "\"{$unit->code}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'code' => [
                'required', 'string', 'max:12',
                'regex:/^[A-Za-z0-9]+$/',
                Rule::unique('units', 'code')->ignore($unit?->id),
            ],
            'allow_decimal' => ['boolean'],
            'precision' => ['nullable', 'integer', 'between:0,4'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ], [
            'code.regex' => 'Use letters and numbers only, with no spaces.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $allowDecimal = (bool) ($data['allow_decimal'] ?? false);

        return [
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'allow_decimal' => $allowDecimal,
            // A whole-unit measure with 3 decimals of precision is a
            // contradiction the rest of the app should never have to read
            // around, so it is resolved here rather than at every call site.
            'precision' => $allowDecimal ? (int) ($data['precision'] ?? 2) : 0,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'code' => $unit->code,
            'is_active' => $unit->is_active,
        ];
    }
}
