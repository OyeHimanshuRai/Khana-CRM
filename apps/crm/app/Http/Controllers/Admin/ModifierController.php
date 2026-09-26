<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Modifier;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Add-ons and modifiers (§8): "Choose your crust", "Add extra toppings".
 *
 * A question and its answers are edited together, in one form, in one
 * transaction. They are not separable in any way a user would recognise -
 * "Choose your crust" with no options is not a half-finished thing to save,
 * it is a question that would break every pizza on the card.
 *
 * Which dishes ask it is edited here too, rather than on each dish, because
 * that is the direction the work actually flows: a restaurant adds a toppings
 * question once and ticks the six pizzas, not the other way round. The
 * product form still shows what a dish asks, read-only.
 */
class ModifierController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $modifiers = $this->filtered($request)
            ->with('options')
            ->withCount(['options', 'products'])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'modifiers' => $modifiers,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Modifier::count(),
                'active' => Modifier::where('is_active', true)->count(),
                'required' => Modifier::where('min_select', '>', 0)->count(),
                // The number that says whether the feature is doing anything:
                // a question attached to nothing is a question nobody is asked.
                'unattached' => Modifier::query()->doesntHave('products')->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.modifiers._list', $data)
            : view('admin.modifiers.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Modifier::query()
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
        return view('admin.modifiers._form', [
            'modifier' => new Modifier([
                'min_select' => 0,
                'max_select' => 1,
                'is_active' => true,
            ]),
            'options' => collect(),
            'products' => $this->pickableProducts(),
            'attached' => [],
        ]);
    }

    public function edit(Modifier $modifier): View
    {
        return view('admin.modifiers._form', [
            'modifier' => $modifier,
            'options' => $modifier->options,
            'products' => $this->pickableProducts(),
            'attached' => $modifier->products()->pluck('products.id')->all(),
        ]);
    }

    public function show(Modifier $modifier): View
    {
        return view('admin.modifiers._show', [
            'modifier' => $modifier->load(['options', 'products:id,name']),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $modifier = DB::transaction(function () use ($data) {
            $modifier = new Modifier($this->attributes($data));
            $modifier->save();

            $this->syncOptions($modifier, $data['options']);
            $this->syncProducts($modifier, $data['products'] ?? []);

            return $modifier;
        });

        ActivityLog::record('modifier.created', "Created add-on \"{$modifier->name}\"", $modifier);

        return ApiResponse::success("\"{$modifier->name}\" created.", $this->payload($modifier));
    }

    public function update(Request $request, Modifier $modifier): JsonResponse
    {
        $data = $this->validated($request, $modifier);

        DB::transaction(function () use ($modifier, $data) {
            $attributes = $this->attributes($data);
            // Fixed: the prices on its options belong to this branch.
            unset($attributes['shop_id']);

            $modifier->fill($attributes)->save();

            $this->syncOptions($modifier, $data['options']);
            $this->syncProducts($modifier, $data['products'] ?? []);
        });

        ActivityLog::record('modifier.updated', "Updated add-on \"{$modifier->name}\"", $modifier);

        return ApiResponse::success("\"{$modifier->name}\" updated.", $this->payload($modifier));
    }

    /**
     * Remove a question.
     *
     * Refused while dishes still ask it. Cascading would silently take a
     * required choice off six pizzas, and the next order for one would be
     * accepted with no crust - a failure nobody would trace back to here.
     */
    public function destroy(Modifier $modifier): JsonResponse
    {
        $dishes = $modifier->products()->count();

        if ($dishes > 0) {
            return ApiResponse::error(sprintf(
                '"%s" is still asked of %d dish%s. Untick them first.',
                $modifier->name,
                $dishes,
                $dishes === 1 ? '' : 'es',
            ));
        }

        $name = $modifier->name;
        $modifier->delete();

        ActivityLog::record('modifier.deleted', "Deleted add-on \"{$name}\"");

        return ApiResponse::success("\"{$name}\" deleted.");
    }

    public function toggleStatus(Modifier $modifier): JsonResponse
    {
        $active = ! $modifier->is_active;

        $modifier->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'modifier.activated' : 'modifier.deactivated',
            ($active ? 'Activated' : 'Deactivated')." add-on \"{$modifier->name}\"",
            $modifier,
        );

        return ApiResponse::success(
            "\"{$modifier->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Options, written as one set.
     *
     * Rows the form no longer carries are deleted rather than deactivated:
     * an option removed from a question was a mistake being corrected, and a
     * soft-deleted "Extra cheeze" would haunt the picker forever. Past orders
     * are unaffected - an order line copies the name and the price it was
     * charged at, exactly as invoice lines copy a tax rate.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncOptions(Modifier $modifier, array $rows): void
    {
        $keep = [];

        foreach (array_values($rows) as $order => $row) {
            $option = $modifier->options()->updateOrCreate(
                ['name' => trim((string) $row['name'])],
                [
                    'price' => (float) ($row['price'] ?? 0),
                    'is_default' => (bool) ($row['is_default'] ?? false),
                    'is_available' => (bool) ($row['is_available'] ?? true),
                    'sort_order' => $order,
                ],
            );

            $keep[] = $option->id;
        }

        $modifier->options()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function syncProducts(Modifier $modifier, array $ids): void
    {
        /*
         | Every dish gets the question at the question's own position. The
         | pivot can carry a different order per dish - a burger that wants
         | its toppings asked before its sauce - but nothing on this screen
         | sets that, and inventing an order here would overwrite one
         | somebody had arranged elsewhere.
         */
        $pivot = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->mapWithKeys(fn (int $id) => [$id => ['sort_order' => (int) $modifier->sort_order]])
            ->all();

        $modifier->products()->sync($pivot);
    }

    /**
     * The dishes a question can be attached to.
     *
     * Active rows only: a question cannot usefully be asked of something
     * withdrawn from the menu, and listing eighty inactive dishes would bury
     * the ones a user is looking for.
     */
    private function pickableProducts()
    {
        return Product::query()
            ->active()
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'category_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Modifier $modifier = null): array
    {
        $shopId = $modifier?->shop_id
            ?? (int) $request->integer('shop_id', (int) CurrentShop::idForWrite());

        return $request->validate([
            'shop_id' => [
                $modifier ? 'nullable' : 'required',
                'integer',
                Rule::in(CurrentShop::accessibleIds()),
            ],
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('modifiers', 'name')
                    ->where('shop_id', $shopId)
                    ->ignore($modifier?->id),
            ],
            'instruction' => ['nullable', 'string', 'max:160'],

            'min_select' => ['required', 'integer', 'between:0,20'],
            /*
             | Nullable is "no ceiling" - as many toppings as you like - which
             | is a different answer from 0 and is why this is not
             | zero-as-unlimited. gte:min_select rather than gt, because
             | "choose exactly 2" is min 2 max 2.
             */
            'max_select' => ['nullable', 'integer', 'between:1,20', 'gte:min_select'],

            'options' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.name' => ['required', 'string', 'max:120'],
            // Signed: "no cheese, -20" is a real line on a real menu.
            'options.*.price' => ['nullable', 'numeric', 'between:-99999,99999'],
            'options.*.is_default' => ['nullable', 'boolean'],
            'options.*.is_available' => ['nullable', 'boolean'],

            'products' => ['nullable', 'array'],
            'products.*' => ['integer', 'exists:products,id'],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,65535'],
        ], [
            'options.required' => 'A question needs at least one answer.',
            'options.min' => 'A question needs at least one answer.',
            'max_select.gte' => 'The most somebody may choose cannot be fewer than the least.',
            'name.unique' => 'This branch already has an add-on with that name.',
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
            'instruction' => $data['instruction'] ?? null,
            'min_select' => (int) $data['min_select'],
            'max_select' => ($data['max_select'] ?? null) === null ? null : (int) $data['max_select'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Modifier $modifier): array
    {
        return [
            'id' => $modifier->id,
            'name' => $modifier->name,
            'options' => $modifier->options()->count(),
            'dishes' => $modifier->products()->count(),
        ];
    }
}
