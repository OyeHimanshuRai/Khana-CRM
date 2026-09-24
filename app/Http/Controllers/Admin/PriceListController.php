<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\PriceLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Prices that apply only sometimes (SRS 8, 16).
 *
 * Every write here forgets the price memo. A memo that outlived an edit would
 * keep a happy hour running after somebody switched it off, and that is the
 * direction of this bug that costs money rather than merely confusing people.
 */
class PriceListController extends Controller
{
    public function index(Request $request): View
    {
        $lists = PriceList::query()
            ->withCount('items')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $data = [
            'lists' => $lists,
            // Which are running this minute - the thing somebody opening this
            // screen at four o'clock actually wants to know.
            'runningNow' => $lists->filter(fn (PriceList $l) => $l->appliesAt(Carbon::now()))->pluck('id'),
        ];

        return $request->header('X-Fragment')
            ? view('admin.price-lists._list', $data)
            : view('admin.price-lists.index', $data);
    }

    public function create(): View
    {
        return view('admin.price-lists._form', [
            'list' => new PriceList(['is_active' => true, 'priority' => 0]),
            'products' => $this->products(),
            'rows' => collect(),
        ]);
    }

    public function edit(PriceList $priceList): View
    {
        return view('admin.price-lists._form', [
            'list' => $priceList,
            'products' => $this->products(),
            'rows' => $priceList->items()->with('product:id,name')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $list = PriceList::create($data['list'] + ['shop_id' => CurrentShop::idForWrite()]);

        $this->syncItems($list, $data['items']);

        ActivityLog::record('price_list.created', "Created price list \"{$list->name}\"", $list);

        PriceLists::forget();

        return ApiResponse::success("\"{$list->name}\" saved.");
    }

    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $this->validated($request, $priceList);

        $priceList->fill($data['list'])->save();

        $this->syncItems($priceList, $data['items']);

        ActivityLog::record('price_list.updated', "Updated price list \"{$priceList->name}\"", $priceList);

        PriceLists::forget();

        return ApiResponse::success("\"{$priceList->name}\" updated.");
    }

    /**
     * Switch a list on or off.
     *
     * The control somebody reaches for when an offer has to stop right now,
     * so it is one action rather than an edit.
     */
    public function toggle(PriceList $priceList): JsonResponse
    {
        $priceList->forceFill(['is_active' => ! $priceList->is_active])->save();

        PriceLists::forget();

        $state = $priceList->is_active ? 'running' : 'switched off';

        ActivityLog::record('price_list.status', "Price list \"{$priceList->name}\" is now {$state}", $priceList);

        return ApiResponse::success("\"{$priceList->name}\" is {$state}.");
    }

    public function destroy(PriceList $priceList): JsonResponse
    {
        $name = $priceList->name;
        $priceList->delete();

        PriceLists::forget();

        ActivityLog::record('price_list.deleted', "Deleted price list \"{$name}\"", $priceList);

        return ApiResponse::success("\"{$name}\" deleted. Prices are back to normal.");
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Replace the list's rows with what was submitted.
     *
     * Wholesale rather than diffed: the form posts the whole set, and a diff
     * would have to guess at what a missing row meant. Deleting and rewriting
     * a handful of rows inside one request is cheap and unambiguous.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(PriceList $list, array $items): void
    {
        $list->items()->delete();

        foreach ($items as $row) {
            PriceListItem::create([
                'price_list_id' => $list->id,
                'product_id' => $row['product_id'],
                'product_variant_id' => $row['product_variant_id'] ?? null,
                'price' => $row['price'] ?? null,
                'discount_percent' => $row['discount_percent'] ?? null,
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, Product> */
    private function products()
    {
        return Product::query()->sellable()->orderBy('name')->get(['id', 'name', 'selling_price']);
    }

    /**
     * @return array{list: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function validated(Request $request, ?PriceList $list = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:90'],
            'code' => [
                'required', 'string', 'max:40', 'alpha_dash',
                Rule::unique('price_lists', 'code')
                    ->where('shop_id', CurrentShop::idForWrite())
                    ->ignore($list?->id)
                    ->withoutTrashed(),
            ],

            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],

            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],

            'channel' => ['nullable', Rule::in(['dine_in', 'takeaway', 'delivery', 'online'])],
            'priority' => ['nullable', 'integer', 'between:0,1000'],
            'is_active' => ['boolean'],

            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        /*
         | One or the other, never both. Storing both would mean two sources
         | of truth for one number, and whichever the code happened to check
         | first would silently become the rule.
         */
        $items = [];

        foreach ($data['items'] ?? [] as $row) {
            $hasPrice = filled($row['price'] ?? null);
            $hasPercent = filled($row['discount_percent'] ?? null);

            if (! $hasPrice && ! $hasPercent) {
                // A row somebody added and left blank. Dropped rather than
                // stored as a no-op nobody can explain later.
                continue;
            }

            $items[] = [
                'product_id' => (int) $row['product_id'],
                'price' => $hasPrice ? (float) $row['price'] : null,
                // A flat price wins if somebody filled in both.
                'discount_percent' => $hasPrice ? null : (float) $row['discount_percent'],
            ];
        }

        return [
            'list' => [
                'name' => $data['name'],
                'code' => $data['code'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'starts_at' => filled($data['starts_at'] ?? null) ? $data['starts_at'].':00' : null,
                'ends_at' => filled($data['ends_at'] ?? null) ? $data['ends_at'].':00' : null,
                // Null is every day; [] would be a list that runs on no day
                // at all, which is a thing somebody may genuinely configure.
                'weekdays' => empty($data['weekdays']) ? null : array_values(array_map('intval', $data['weekdays'])),
                'channel' => $data['channel'] ?? null,
                'priority' => (int) ($data['priority'] ?? 0),
                'is_active' => $request->boolean('is_active'),
            ],
            'items' => $items,
        ];
    }
}
