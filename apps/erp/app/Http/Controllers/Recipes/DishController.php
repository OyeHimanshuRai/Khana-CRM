<?php

namespace App\Http\Controllers\Recipes;

use App\Models\Recipe;
use App\Models\SalesAddon;
use App\Models\SalesItem;
use App\Models\SalesItemSize;
use App\Services\Availability;
use App\Services\Recipes;
use App\Support\Here;
use App\Support\Lines;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Recipes for what guests order.
 *
 * The list leads with what needs doing: every dish and size nobody has
 * written a recipe for. Below it is every dish with what a portion costs,
 * its food-cost percent and whether stock can still cover it.
 *
 * A dish with sizes has one recipe a size - or one shared by every size,
 * which a size's own recipe then replaces.
 */
final class DishController
{
    public function index(Request $request, Recipes $recipes, Availability $availability): View
    {
        $outlet = Here::outlet();
        $search = trim((string) $request->query('q'));

        $dishes = SalesItem::forOutlet($outlet)
            ->active()
            ->with(['sizes' => fn ($q) => $q->where('is_active', true)])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('category')->orderBy('name')
            ->paginate(40)
            ->withQueryString();

        return view('recipes.index', [
            'search' => $search,
            'needs' => $search === '' ? $recipes->needsRecipe($outlet) : collect(),
            'dishes' => $dishes,
            'snapshots' => $dishes->getCollection()->mapWithKeys(fn (SalesItem $item) => [$item->id => $availability->snapshot($item)]),
            'addons' => SalesAddon::forOutlet($outlet)->where('is_active', true)->orderBy('group_name')->orderBy('name')->get(),
            'addonRecipes' => Recipe::forOutlet($outlet)->where('sales_addon_id', '>', 0)->pluck('id', 'sales_addon_id'),
        ]);
    }

    public function edit(Recipes $recipes, Availability $availability, int $item, ?int $size = null): View
    {
        $outlet = Here::outlet();
        $dish = SalesItem::forOutlet($outlet)->with(['sizes' => fn ($q) => $q->where('is_active', true)])->findOrFail($item);
        $sizeRow = $size ? $dish->sizes->firstWhere('id', $size) ?? abort(404) : null;

        // The recipe written for exactly this target - not the shared one
        // a size falls back to, which is shown but edited on its own page.
        $own = Recipe::forOutlet($outlet)->with('lines.item')
            ->where('sales_item_id', $dish->id)->where('sales_item_size_id', $sizeRow?->id ?? 0)->first();

        return view('recipes.edit', [
            'dish' => $dish,
            'size' => $sizeRow,
            'recipe' => $own,
            'shared' => $sizeRow !== null && $own === null ? $recipes->find($outlet, $dish->id, 0) : null,
            'snapshot' => collect($availability->snapshot($dish))->firstWhere('size_id', $sizeRow?->id ?? 0)
                ?? collect($availability->snapshot($dish))->first(),
            'catalogue' => Lines::catalogue(),
            'rows' => $this->rows($own),
        ]);
    }

    public function update(Request $request, Recipes $recipes, int $item, ?int $size = null): RedirectResponse
    {
        $outlet = Here::outlet();
        $dish = SalesItem::forOutlet($outlet)->findOrFail($item);
        $sizeRow = $size ? SalesItemSize::query()->where('sales_item_id', $dish->id)->findOrFail($size) : null;

        try {
            $recipes->save(
                $outlet,
                ['sales_item_id' => $dish->id, 'sales_item_size_id' => $sizeRow?->id ?? 0],
                Lines::from($request),
                notes: $request->string('notes')->trim()->value() ?: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('recipes.index')
            ->with('status', 'Recipe saved for '.$dish->name.($sizeRow ? ' ('.$sizeRow->name.')' : '').'.');
    }

    public function destroy(Recipes $recipes, int $item, ?int $size = null): RedirectResponse
    {
        $recipe = Recipe::forOutlet(Here::outlet())
            ->where('sales_item_id', $item)->where('sales_item_size_id', $size ?? 0)->firstOrFail();

        $recipes->delete($recipe);

        return redirect()->route('recipes.index')->with('status', 'Recipe removed.');
    }

    /* ----------------------------------------------------------- add-ons */

    public function editAddon(int $addon): View
    {
        $outlet = Here::outlet();
        $addon = SalesAddon::forOutlet($outlet)->findOrFail($addon);
        $recipe = Recipe::forOutlet($outlet)->with('lines.item')->where('sales_addon_id', $addon->id)->first();

        return view('recipes.addon', [
            'addon' => $addon,
            'recipe' => $recipe,
            'catalogue' => Lines::catalogue(),
            'rows' => $this->rows($recipe),
        ]);
    }

    public function updateAddon(Request $request, int $addon, Recipes $recipes): RedirectResponse
    {
        $outlet = Here::outlet();
        $addon = SalesAddon::forOutlet($outlet)->findOrFail($addon);

        $lines = Lines::from($request, required: false);

        try {
            if ($lines === []) {
                // Emptied on purpose: the add-on uses nothing.
                Recipe::forOutlet($outlet)->where('sales_addon_id', $addon->id)->get()->each(fn (Recipe $recipe) => $recipes->delete($recipe));
            } else {
                $recipes->save($outlet, ['sales_addon_id' => $addon->id], $lines);
            }
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('recipes.index')->with('status', 'Recipe saved for '.$addon->name.'.');
    }

    /** A recipe's lines as the form's rows. */
    private function rows(?Recipe $recipe): array
    {
        return $recipe?->lines->map(fn ($line) => [
            'item' => $line->stock_item_id,
            'qty' => $line->entered_qty,
            'unit' => $line->entered_unit,
            'optional' => $line->is_optional,
        ])->all() ?? [];
    }
}
