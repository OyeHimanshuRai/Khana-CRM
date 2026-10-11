<?php

namespace App\Http\Controllers\Stock;

use App\Models\StockItem;
use App\Support\Here;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The things the store buys, holds and cooks with.
 *
 * One form: the item, the unit it is kept in and the packs it is bought in.
 * The base unit cannot change once there is stock or a recipe written in
 * it - every stored quantity would silently mean something else.
 */
final class ItemController
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        return view('items.index', [
            'search' => $search,
            'items' => Here::items()
                ->with('packs')
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")))
                ->orderByDesc('is_active')->orderBy('category')->orderBy('name')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function create(): View
    {
        return view('items.form', $this->formData(new StockItem(['base_unit' => 'g', 'is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $item = StockItem::forTenant(Here::tenant())->create($this->attributes($data) + ['tenant_id' => Here::tenant()]);
            $this->savePacks($item, $data['packs'] ?? []);
        });

        return redirect()->route('items.index')->with('status', $data['name'].' added.');
    }

    public function edit(int $item): View
    {
        return view('items.form', $this->formData(Here::items()->with('packs')->findOrFail($item)));
    }

    public function update(Request $request, int $item): RedirectResponse
    {
        $item = Here::items()->with('packs')->findOrFail($item);
        $data = $this->validated($request, $item);

        if ($data['base_unit'] !== $item->base_unit && $this->inUse($item)) {
            return back()->withInput()->withErrors([
                'base_unit' => 'This item already has stock or a recipe in '.$item->base_unit.', so its unit cannot change. Add a new item instead.',
            ]);
        }

        DB::transaction(function () use ($item, $data) {
            $item->update($this->attributes($data));
            $this->savePacks($item, $data['packs'] ?? []);
        });

        return redirect()->route('items.index')->with('status', $item->name.' saved.');
    }

    /* ---------------------------------------------------------- private */

    private function formData(StockItem $item): array
    {
        return [
            'item' => $item,
            'bases' => Units::BASE,
            'categories' => Here::items()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'unitLocked' => $item->exists && $this->inUse($item),
        ];
    }

    private function validated(Request $request, ?StockItem $item = null): array
    {
        $tenant = Here::tenant();

        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:64', Rule::unique('stock_items', 'code')->where('tenant_id', $tenant)->ignore($item?->id)],
            'barcode' => ['nullable', 'string', 'max:64'],
            'category' => ['nullable', 'string', 'max:100'],
            'base_unit' => ['required', Rule::in(array_keys(Units::BASE))],
            'is_prepared' => ['nullable', 'boolean'],
            'track_expiry' => ['nullable', 'boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'hsn' => ['nullable', 'string', 'max:12'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:40'],
            'reorder' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'packs' => ['nullable', 'array', 'max:10'],
            'packs.*.id' => ['nullable', 'integer'],
            'packs.*.name' => ['nullable', 'string', 'max:60'],
            'packs.*.size' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'category' => $data['category'] ?? null,
            'base_unit' => $data['base_unit'],
            'is_prepared' => (bool) ($data['is_prepared'] ?? false),
            'track_expiry' => (bool) ($data['track_expiry'] ?? false),
            'shelf_life_days' => $data['shelf_life_days'] ?? null,
            'hsn' => $data['hsn'] ?? null,
            'gst_rate_bp' => (int) round(((float) ($data['gst_rate'] ?? 0)) * 100),
            // Typed in the big unit - kilos, litres, pieces.
            'reorder_qty' => Units::toBase($data['reorder'] ?? 0, self::bigUnit($data['base_unit']), $data['base_unit']),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    /**
     * Packs are typed as a name and how much of the big unit one holds:
     * "5 kg bag" is 5. A row with no name or no size is a row left blank.
     */
    private function savePacks(StockItem $item, array $packs): void
    {
        $kept = [];

        foreach ($packs as $pack) {
            if (blank($pack['name'] ?? null) || (float) ($pack['size'] ?? 0) <= 0) {
                continue;
            }

            $qty = Units::toBase($pack['size'], self::bigUnit($item->base_unit), $item->base_unit);
            $row = $item->packs()->updateOrCreate(['id' => $pack['id'] ?? null], ['name' => $pack['name'], 'qty' => $qty]);
            $kept[] = $row->id;
        }

        $item->packs()->whereNotIn('id', $kept)->delete();
    }

    private function inUse(StockItem $item): bool
    {
        return DB::table('stock_movements')->where('stock_item_id', $item->id)->exists()
            || (DB::getSchemaBuilder()->hasTable('recipe_lines') && DB::table('recipe_lines')->where('stock_item_id', $item->id)->exists());
    }

    public static function bigUnit(string $base): string
    {
        return match ($base) {
            'g' => 'kg',
            'ml' => 'l',
            default => 'pc',
        };
    }
}
