<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TaxRate;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * GST slabs.
 *
 * Editing one does not rewrite tax on invoices already raised: lines copy
 * the numbers at the moment they are billed. That is worth knowing when
 * reading the "used by N products" count - it is the future, not the past.
 */
class TaxRateController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $rates = $this->filtered($request)
            ->withCount('products')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'rates' => $rates,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => TaxRate::count(),
                'active' => TaxRate::where('is_active', true)->count(),
                'zero' => TaxRate::where('rate', 0)->count(),
                'highest' => (float) TaxRate::max('rate'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.taxes._list', $data)
            : view('admin.taxes.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return TaxRate::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->orderBy('sort_order')
            ->orderBy('rate');
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.taxes._form', ['rate' => new TaxRate()]);
    }

    public function edit(TaxRate $tax): View
    {
        return view('admin.taxes._form', ['rate' => $tax]);
    }

    public function show(TaxRate $tax): View
    {
        return view('admin.taxes._show', ['rate' => $tax->loadCount('products')]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $rate = DB::transaction(function () use ($data) {
            $rate = TaxRate::create($this->attributes($data));
            $this->settleDefault($rate);

            return $rate;
        });

        ActivityLog::record('tax_rate.created', "Created tax slab \"{$rate->name}\"", $rate);

        return ApiResponse::success("Tax slab \"{$rate->name}\" created.", $this->payload($rate));
    }

    public function update(Request $request, TaxRate $tax): JsonResponse
    {
        $data = $this->validated($request, $tax);

        DB::transaction(function () use ($tax, $data) {
            $tax->fill($this->attributes($data))->save();
            $this->settleDefault($tax);
        });

        ActivityLog::record('tax_rate.updated', "Updated tax slab \"{$tax->name}\"", $tax);

        return ApiResponse::success("Tax slab \"{$tax->name}\" updated.", $this->payload($tax));
    }

    public function destroy(TaxRate $tax): JsonResponse
    {
        $inUse = $tax->products()->withTrashed()->count();

        if ($inUse > 0) {
            return ApiResponse::error(sprintf(
                '"%s" is used by %d product%s. Move those to another slab first.',
                $tax->name,
                $inUse,
                $inUse === 1 ? '' : 's',
            ));
        }

        $name = $tax->name;
        $tax->delete();

        ActivityLog::record('tax_rate.deleted', "Deleted tax slab \"{$name}\"");

        return ApiResponse::success("Tax slab \"{$name}\" deleted.");
    }

    public function toggleStatus(TaxRate $tax): JsonResponse
    {
        $active = ! $tax->is_active;

        $tax->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'tax_rate.activated' : 'tax_rate.deactivated',
            ($active ? 'Activated' : 'Deactivated')." tax slab \"{$tax->name}\"",
            $tax,
        );

        return ApiResponse::success(
            "\"{$tax->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Keep exactly one slab flagged as the default.
     *
     * Two defaults would make "which slab does a new product get" depend on
     * row order, which is the kind of answer that changes silently.
     */
    private function settleDefault(TaxRate $rate): void
    {
        if (! $rate->is_default) {
            return;
        }

        TaxRate::where('id', '!=', $rate->id)->update(['is_default' => false]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?TaxRate $rate = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('tax_rates', 'name')->ignore($rate?->id),
            ],
            'rate' => ['required', 'numeric', 'between:0,100'],
            'cgst' => ['nullable', 'numeric', 'between:0,100'],
            'sgst' => ['nullable', 'numeric', 'between:0,100'],
            'igst' => ['nullable', 'numeric', 'between:0,100'],
            'cess' => ['nullable', 'numeric', 'between:0,100'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $rate = (float) $data['rate'];

        /*
         | The splits default to the even one that almost every Indian slab
         | uses, so the common case is one field rather than four. A slab
         | that genuinely splits unevenly can still say so.
         */
        return [
            'name' => $data['name'],
            'rate' => $rate,
            'cgst' => isset($data['cgst']) ? (float) $data['cgst'] : $rate / 2,
            'sgst' => isset($data['sgst']) ? (float) $data['sgst'] : $rate / 2,
            'igst' => isset($data['igst']) ? (float) $data['igst'] : $rate,
            'cess' => (float) ($data['cess'] ?? 0),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TaxRate $rate): array
    {
        return [
            'id' => $rate->id,
            'name' => $rate->name,
            'rate' => (float) $rate->rate,
            'is_active' => $rate->is_active,
        ];
    }
}
