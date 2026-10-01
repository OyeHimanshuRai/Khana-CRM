<?php

namespace App\Http\Controllers\Recipes;

use App\Models\Recipe;
use App\Services\Recipes;
use App\Support\Here;
use App\Support\Lines;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

/**
 * Recipes for what the kitchen makes for itself: a gravy base, a dough.
 *
 * A prepared item is a stock item with a recipe. The recipe says what one
 * batch takes and how much it makes; "Production" then makes any amount in
 * proportion, and dishes use the result like any other ingredient.
 */
final class PreparedController
{
    public function index(Recipes $recipes): View
    {
        $outlet = Here::outlet();
        $made = Recipe::forOutlet($outlet)->with('lines.item')->where('stock_item_id', '>', 0)->get()->keyBy('stock_item_id');

        return view('prepared.index', [
            'items' => Here::items()->active()->where('is_prepared', true)->orderBy('name')->get(),
            'recipes' => $made,
            'costs' => $made->map(fn (Recipe $recipe) => $recipes->cost($recipe)),
        ]);
    }

    public function edit(int $item, Recipes $recipes): View
    {
        $item = Here::items()->with('packs')->where('is_prepared', true)->findOrFail($item);
        $recipe = $recipes->forPrepared(Here::outlet(), $item->id);

        return view('prepared.edit', [
            'item' => $item,
            'recipe' => $recipe,
            'yield' => $recipe ? Units::plain($item->base_unit === 'pc' ? $recipe->yield_qty : intdiv($recipe->yield_qty, 1000)) : '',
            'big' => Units::big($item->base_unit),
            // Something made in-house cannot be an ingredient of itself.
            'catalogue' => array_diff_key(Lines::catalogue(), [$item->id => true]),
            'rows' => $recipe?->lines->map(fn ($line) => [
                'item' => $line->stock_item_id, 'qty' => $line->entered_qty, 'unit' => $line->entered_unit,
            ])->all() ?? [],
        ]);
    }

    public function update(Request $request, int $item, Recipes $recipes): RedirectResponse
    {
        $item = Here::items()->where('is_prepared', true)->findOrFail($item);

        $data = $request->validate([
            'yield' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['yield.*' => 'Say how much one batch of this recipe makes.']);

        try {
            $recipes->save(
                Here::outlet(),
                ['stock_item_id' => $item->id],
                Lines::from($request),
                Units::toBase($data['yield'], Units::big($item->base_unit), $item->base_unit),
                $data['notes'] ?? null,
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('prepared.index')->with('status', 'Recipe saved for '.$item->name.'.');
    }
}
