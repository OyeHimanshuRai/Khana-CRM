<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\RecipeService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Recipes: what a dish is made of (§10).
 *
 * One screen, listing every dish with the ingredients behind it and what they
 * cost, because the question this answers is comparative - "which of my dishes
 * am I not making money on" - and a recipe tucked inside each product's edit
 * form would make that question take forty clicks.
 *
 * Editing is a modal per dish, and per size where a dish has sizes: a Full
 * biryani uses more rice than a Half, and a restaurant that costed both the
 * same would price one of them wrong.
 */
class RecipeController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function __construct(private readonly RecipeService $recipes) {}

    public function index(Request $request): View|RedirectResponse
    {
        /*
         | A recipe belongs to one kitchen - the same chicken tikka is made
         | with more cream in one branch than another. Costing a menu across
         | branches would add two kitchens' rows together and report a figure
         | that is true of neither.
         */
        if (CurrentShop::id() === null) {
            return redirect()
                ->route('admin.dashboard')
                ->with('error', 'Choose a single shop — a recipe belongs to one kitchen.');
        }

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $dishes = $this->filtered($request)
            ->with(['unit:id,code', 'category:id,name', 'variants:id,product_id,name,price'])
            ->withCount('recipeItems')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'dishes' => $dishes,
            // One query for the page's costs rather than one per row. See
            // RecipeService::costMap().
            'costs' => $this->recipes->costMap($dishes->getCollection(), CurrentShop::id()),
            'search' => $request->string('q')->toString(),
            'has' => $request->string('has')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'shopId' => CurrentShop::id(),
            'recipes' => $this->recipes,
            'moment' => CurrentShop::get()
                ? $this->recipes->momentFor(CurrentShop::get())
                : RecipeService::ON_READY,
            'moments' => RecipeService::MOMENTS,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.recipes._list', $data)
            : view('admin.recipes.index', $data);
    }

    /**
     * Dishes, never ingredients.
     *
     * A recipe is a thing you sell made of things you don't, so the list is
     * `sellable()` - which excludes ingredients by definition.
     */
    private function filtered(Request $request): Builder
    {
        return Product::query()
            ->sellable()
            ->search($request->string('q')->toString())
            ->when($request->string('has')->toString(), function (Builder $query, string $has) {
                $has === 'yes'
                    ? $query->has('recipeItems')
                    : $query->doesntHave('recipeItems');
            })
            ->orderBy('name');
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $dishes = Product::query()->sellable()->where('is_made_to_order', true);

        return [
            'dishes' => (clone $dishes)->count(),
            'costed' => (clone $dishes)->has('recipeItems')->count(),
            'ingredients' => Product::query()->where('is_ingredient', true)->where('is_active', true)->count(),
        ];
    }

    /* ------------------------------------------------------ modal screens */

    /** Read-only: what this dish is made of, and what that costs. */
    public function show(Request $request, Product $product): View
    {
        return view('admin.recipes._show', [
            'dish' => $product->load('variants'),
            'variantId' => $this->variantId($request, $product),
            'recipes' => $this->recipes,
            'shopId' => CurrentShop::id(),
        ]);
    }

    public function edit(Request $request, Product $product): View
    {
        $variantId = $this->variantId($request, $product);

        return view('admin.recipes._form', [
            'dish' => $product->load('variants'),
            'variantId' => $variantId,
            'components' => $this->recipes->componentsFor($product, $variantId, CurrentShop::id()),
            'ingredients' => $this->ingredients(),
            'recipes' => $this->recipes,
            'shopId' => CurrentShop::id(),
            /*
             | Whether what is on screen is the dish's own rows or the generic
             | ones standing in for them. Saving turns the second into the
             | first, and the form says so out loud rather than letting
             | somebody think they are editing what they can see.
             */
            'inherited' => $variantId !== null
                && $product->recipeItems()->where('product_variant_id', $variantId)->doesntExist(),
        ]);
    }

    /* ------------------------------------------------------------- writes */

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'product_variant_id' => ['nullable', 'integer'],
            'rows' => ['nullable', 'array'],
            'rows.*.ingredient_id' => ['nullable', 'integer'],
            'rows.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'rows.*.note' => ['nullable', 'string', 'max:190'],
        ]);

        $variantId = $data['product_variant_id'] ?? null;

        if ($variantId !== null && ! $product->variants()->whereKey($variantId)->exists()) {
            return ApiResponse::error('That size does not belong to this dish.', [], 404);
        }

        try {
            $kept = $this->recipes->save($product, $variantId, $data['rows'] ?? []);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        $cost = $this->recipes->costOf($product, $variantId, CurrentShop::id());

        return ApiResponse::success(
            $kept === 0
                ? sprintf('Recipe for "%s" cleared.', $product->name)
                : sprintf(
                    'Recipe for "%s" saved — %d ingredient%s, ₹%s to make.',
                    $product->name,
                    $kept,
                    $kept === 1 ? '' : 's',
                    number_format((float) $cost, 2),
                ),
            ['ingredients' => $kept, 'cost' => $cost],
        );
    }

    /* ------------------------------------------------------------ export */

    /**
     * Every recipe, flat, one row per ingredient.
     *
     * Flat rather than nested because this is opened in a spreadsheet, and a
     * head chef costing a menu wants to sort the whole thing by ingredient.
     */
    public function export(): StreamedResponse
    {
        $shopId = CurrentShop::id();

        $dishes = Product::query()
            ->sellable()
            ->has('recipeItems')
            ->with(['recipeItems.ingredient.unit', 'recipeItems.variant'])
            ->orderBy('name')
            ->get();

        return response()->streamDownload(function () use ($dishes, $shopId) {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Dish', 'Size', 'Ingredient', 'Quantity', 'Unit', 'Cost', 'Note']);

            foreach ($dishes as $dish) {
                foreach ($dish->recipeItems as $item) {
                    fputcsv($handle, [
                        $dish->name,
                        $item->variant?->name ?? 'All sizes',
                        $item->ingredient?->name,
                        rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.'),
                        $item->ingredient?->unit?->code,
                        number_format($item->cost($shopId), 2, '.', ''),
                        $item->note,
                    ]);
                }
            }

            fclose($handle);
        }, 'recipes-'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * The ingredients this shop can cook with.
     *
     * @return Collection<int, Product>
     */
    private function ingredients(): Collection
    {
        return Product::query()
            ->where('is_ingredient', true)
            ->where('is_active', true)
            ->with('unit:id,code')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'unit_id', 'purchase_price']);
    }

    /** The size being edited, or null for the recipe that covers them all. */
    private function variantId(Request $request, Product $product): ?int
    {
        $asked = $request->integer('variant');

        if (! $asked) {
            return null;
        }

        return $product->variants()->whereKey($asked)->exists() ? $asked : null;
    }
}
