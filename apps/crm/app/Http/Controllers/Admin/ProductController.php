<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductStock;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Services\MenuImportService;
use App\Services\ScannerService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The product catalogue.
 *
 * Products are global (see the migration), but the listing still shows
 * shop-specific numbers: the stock and price columns are read for whichever
 * shop is in context. That is the whole point of the split - one catalogue,
 * many shelves.
 *
 * Per-shop price overrides are edited here too, on the shop the user is
 * currently working in. In All-shops mode that section is hidden rather than
 * guessed at: there is no single shop to override.
 */
class ProductController extends Controller
{
    public function __construct(
        private ScannerService $scanner,
        private readonly MenuImportService $menu,
    ) {}

    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'products';

    /** Most gallery images one product may carry. */
    private const MAX_GALLERY = 8;

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $shopId = CurrentShop::id();

        $products = $this->filtered($request)
            ->with(['category:id,name', 'brand:id,name', 'unit:id,code,name,allow_decimal,precision'])
            /*
             | On-hand as a sub-select rather than a join: a join against
             | product_stocks multiplies rows by warehouse and batch, and the
             | pagination count would then be wrong in a way nobody notices
             | until a page is missing.
             */
            ->withSum(['stocks as on_hand' => function ($query) use ($shopId) {
                if ($shopId !== null) {
                    $query->where('shop_id', $shopId);
                } else {
                    $query->whereIn('shop_id', CurrentShop::accessibleIds() ?: [0]);
                }
            }], 'quantity')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'products' => $products,
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'stock' => $request->string('stock')->toString(),
            'categoryId' => $request->integer('category'),
            'brandId' => $request->integer('brand'),
            'sort' => $request->string('sort')->toString() ?: 'name_asc',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            'brands' => Brand::active()->orderBy('name')->get(['id', 'name']),
            'shopId' => $shopId,
            'stats' => $this->stats($shopId),
        ];

        return $request->header('X-Fragment')
            ? view('admin.products._list', $data)
            : view('admin.products.index', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(?int $shopId): array
    {
        $scope = fn ($query) => $shopId !== null
            ? $query->where('shop_id', $shopId)
            : $query->whereIn('shop_id', CurrentShop::accessibleIds() ?: [0]);

        return [
            'total' => Product::count(),
            'active' => Product::where('is_active', true)->count(),
            'published' => Product::where('is_published', true)->count(),
            /*
             | What the shelf is worth.
             |
             | Dishes are left out, and that is the whole point of the
             | clause: a made-to-order row has no shelf, nothing is taken off
             | one when it sells, and any figure sitting against it is stock
             | that will never move. Counting it here would put money in the
             | valuation that no stock-take could ever find.
             */
            'stock_value' => (float) $scope(ProductStock::query())
                ->whereExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.id', 'product_stocks.product_id')
                    ->where('products.is_made_to_order', false))
                ->selectRaw('COALESCE(SUM(quantity * average_cost), 0) as total')
                ->value('total'),
        ];
    }

    private function filtered(Request $request): Builder
    {
        $shopId = CurrentShop::id();

        return Product::query()
            ->search($request->string('q')->toString())
            ->when($request->integer('category'), fn (Builder $q, int $id) => $q->where('category_id', $id))
            ->when($request->integer('brand'), fn (Builder $q, int $id) => $q->where('brand_id', $id))
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                match ($status) {
                    'active' => $query->where('is_active', true),
                    'inactive' => $query->where('is_active', false),
                    'published' => $query->where('is_published', true),
                    'unpublished' => $query->where('is_published', false),
                    default => null,
                };
            })
            ->when($request->string('stock')->toString(), function (Builder $query, string $filter) use ($shopId) {
                $scope = function ($stock) use ($shopId) {
                    if ($shopId !== null) {
                        $stock->where('shop_id', $shopId);
                    } else {
                        $stock->whereIn('shop_id', CurrentShop::accessibleIds() ?: [0]);
                    }
                };

                match ($filter) {
                    /*
                     | "Out of stock" has to include products with no stock
                     | row at all, not merely those whose row reads zero -
                     | a product nobody has ever received is exactly what the
                     | reorder list is looking for.
                     */
                    'out' => $query->whereDoesntHave('stocks', function ($stock) use ($scope) {
                        $scope($stock);
                        $stock->where('quantity', '>', 0);
                    }),
                    'in' => $query->whereHas('stocks', function ($stock) use ($scope) {
                        $scope($stock);
                        $stock->where('quantity', '>', 0);
                    }),
                    // A dish has no shelf to run low - see tracksStock().
                    'low' => $query->where('is_made_to_order', false)
                        ->where('reorder_level', '>', 0)
                        ->whereRaw(
                            '(SELECT COALESCE(SUM(quantity), 0) FROM product_stocks
                                WHERE product_stocks.product_id = products.id'
                            .($shopId !== null ? ' AND product_stocks.shop_id = '.(int) $shopId : '')
                            .') <= products.reorder_level'
                        ),
                    default => null,
                };
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'name_desc' => $query->orderByDesc('name'),
                    'price_asc' => $query->orderBy('selling_price'),
                    'price_desc' => $query->orderByDesc('selling_price'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    'sku' => $query->orderBy('sku'),
                    default => $query->orderBy('name'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.products._form', $this->formData(new Product()));
    }

    public function edit(Product $product): View
    {
        return view('admin.products._form', $this->formData($product->load(['images', 'variants', 'modifiers'])));
    }

    public function show(Product $product): View
    {
        $shopId = CurrentShop::id();

        return view('admin.products._show', [
            'product' => $product->load(['category', 'brand', 'unit', 'taxRate', 'images', 'variants', 'modifiers']),
            'shopId' => $shopId,
            'onHand' => $product->stockOnHand($shopId),
            'available' => $product->availableStock($shopId),
            'stockRows' => ProductStock::with(['warehouse:id,name', 'batch:id,batch_no,expiry_date'])
                ->where('product_id', $product->id)
                ->where('quantity', '!=', 0)
                ->orderBy('warehouse_id')
                ->get(),
        ]);
    }

    /**
     * Everything the add/edit form needs.
     *
     * @return array<string, mixed>
     */
    private function formData(Product $product): array
    {
        $shopId = CurrentShop::id();

        return [
            'product' => $product,
            // Always a collection, so the form's loop needs no null check and
            // a brand-new dish renders an empty table rather than nothing.
            'variants' => $product->exists ? $product->variants : collect(),
            'categories' => Category::active()->orderBy('name')->get(['id', 'name']),
            /*
             | Kitchen stations (§9), or an empty collection when the branch
             | runs none - the form leaves the field off entirely rather than
             | ask a question with one answer. Routing is normally set on the
             | category; this is the per-dish exception.
             */
            'stations' => KitchenStation::query()
                ->active()
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'is_default']),
            'brands' => Brand::active()->orderBy('name')->get(['id', 'name']),
            'units' => Unit::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'taxRates' => TaxRate::where('is_active', true)->orderBy('sort_order')->orderBy('rate')->get(),
            'defaultTaxRateId' => $product->tax_rate_id ?? TaxRate::default()?->id,
            'suggestedSku' => $product->exists ? null : Product::generateSku('PRD'),
            // The shop whose price overrides are editable, or null in
            // All-shops mode where there is no single answer.
            'overrideShop' => $shopId ? CurrentShop::get() : null,
            'override' => $shopId && $product->exists ? $product->overrideFor($shopId)?->pivot : null,
            'maxGallery' => self::MAX_GALLERY,
        ];
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $product = DB::transaction(function () use ($request, $data) {
            $product = new Product($this->attributes($data));

            $product->slug = Product::uniqueSlug(
                filled($data['slug'] ?? null) ? $data['slug'] : $data['name']
            );

            $product->sku = filled($data['sku'] ?? null)
                ? strtoupper($data['sku'])
                : Product::generateSku($data['name']);

            if ($request->hasFile('image')) {
                $product->image_path = $this->storeImage($request->file('image'));
            }

            $product->save();

            $this->syncOverride($product, $data);
            $this->syncVariants($product, $data['options'] ?? []);
            $this->storeGallery($request, $product);

            return $product;
        });

        ActivityLog::record(
            'product.created',
            "Created product \"{$product->name}\" ({$product->sku})",
            $product,
        );

        return ApiResponse::success("Product \"{$product->name}\" created.", $this->payload($product));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);

        DB::transaction(function () use ($request, $product, $data) {
            $product->fill($this->attributes($data));

            if (filled($data['slug'] ?? null)) {
                $product->slug = Product::uniqueSlug($data['slug'], $product->id);
            }

            if (filled($data['sku'] ?? null)) {
                $product->sku = strtoupper($data['sku']);
            }

            if ($request->hasFile('image')) {
                $previous = $product->image_path;
                $product->image_path = $this->storeImage($request->file('image'));

                if (filled($previous)) {
                    Storage::disk('public')->delete($previous);
                }
            }

            $product->save();

            $this->syncOverride($product, $data);
            $this->syncVariants($product, $data['options'] ?? []);
            $this->storeGallery($request, $product);
        });

        ActivityLog::record('product.updated', "Updated product \"{$product->name}\"", $product);

        return ApiResponse::success("Product \"{$product->name}\" updated.", $this->payload($product));
    }

    /**
     * Remove a product.
     *
     * Soft, always: invoice lines and stock movements name it, and they have
     * to stay readable. Refused while any shop still holds stock of it -
     * withdrawing something that is physically on a shelf hides it from the
     * count rather than from sale.
     */
    public function destroy(Product $product): JsonResponse
    {
        $held = (float) ProductStock::allShops()
            ->where('product_id', $product->id)
            ->sum('quantity');

        if (abs($held) > 0.0005) {
            return ApiResponse::error(sprintf(
                '"%s" still has %s in stock. Adjust or transfer it out before removing the product.',
                $product->name,
                rtrim(rtrim(number_format($held, 3, '.', ''), '0'), '.'),
            ));
        }

        $name = $product->name;
        $product->delete();

        ActivityLog::record('product.deleted', "Removed product \"{$name}\"");

        return ApiResponse::success("Product \"{$name}\" removed. Its history is kept for audit.");
    }

    public function toggleStatus(Product $product): JsonResponse
    {
        $active = ! $product->is_active;

        $product->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'product.activated' : 'product.deactivated',
            ($active ? 'Activated' : 'Deactivated')." product \"{$product->name}\"",
            $product,
        );

        return ApiResponse::success(
            "\"{$product->name}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /**
     * The instant Sold Out toggle (§8).
     *
     * One tap, from the list, because that is where it is used: a kitchen
     * runs out of prawns mid-service and nobody is opening an edit form to
     * say so. Its own endpoint rather than a corner of the edit action for
     * the same reason - this has to work while somebody is holding a pan.
     *
     * Throwing it clears any "back on at" time. That time is how the flag
     * un-sets itself, and inheriting yesterday's would put a dish back on the
     * menu the moment it was taken off.
     */
    public function toggleSoldOut(Product $product): JsonResponse
    {
        $soldOut = ! $product->is_sold_out;

        $product->forceFill([
            'is_sold_out' => $soldOut,
            'sold_out_until' => null,
        ])->save();

        ActivityLog::record(
            $soldOut ? 'product.sold_out' : 'product.back_on',
            ($soldOut ? 'Marked sold out' : 'Put back on the menu').": \"{$product->name}\"",
            $product,
        );

        return ApiResponse::success(
            $soldOut
                ? "\"{$product->name}\" is off the menu."
                : "\"{$product->name}\" is back on the menu.",
            ['is_sold_out' => $soldOut],
        );
    }

    /** Show or hide the product in the online store. */
    public function togglePublished(Product $product): JsonResponse
    {
        $published = ! $product->is_published;

        if ($published && ! $product->is_active) {
            return ApiResponse::error('Activate the product before publishing it to the store.');
        }

        $product->forceFill(['is_published' => $published])->save();

        ActivityLog::record(
            $published ? 'product.published' : 'product.unpublished',
            ($published ? 'Published' : 'Unpublished')." product \"{$product->name}\"",
            $product,
        );

        return ApiResponse::success(
            "\"{$product->name}\" is ".($published ? 'now in the online store' : 'hidden from the online store').'.',
            ['is_published' => $published],
        );
    }

    public function destroyImage(Product $product): JsonResponse
    {
        if (blank($product->image_path)) {
            return ApiResponse::success('There was no image to remove.', ['image' => null]);
        }

        $path = $product->image_path;
        $product->forceFill(['image_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('product.image_removed', "Removed the image for \"{$product->name}\"", $product);

        return ApiResponse::success('Image removed.', ['image' => null]);
    }

    /** Drop one gallery image; the file goes with the row (see ProductImage). */
    public function destroyGalleryImage(Product $product, ProductImage $image): JsonResponse
    {
        if ($image->product_id !== $product->id) {
            return ApiResponse::error('That image does not belong to this product.', [], 404);
        }

        $image->delete();

        ActivityLog::record('product.gallery_removed', "Removed a gallery image from \"{$product->name}\"", $product);

        return ApiResponse::success('Image removed.');
    }

    /* ------------------------------------------------------------ lookup */

    /**
     * Barcode / SKU / name lookup for the POS.
     *
     * An exact barcode match short-circuits and returns one product, because
     * that is what a scan means - anything else and the counter would have
     * to pick from a list it did not ask for.
     */
    public function lookup(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if ($term === '') {
            return ApiResponse::success('', ['results' => [], 'exact' => null]);
        }

        $shopId = CurrentShop::id();

        /*
        | Exact resolution - "this term is a barcode or SKU that names one
        | product" - is the scanner module's job now (see ScannerService),
        | so a scan into this generic search box and a scan into the
        | dedicated /admin/api/scanner/product endpoint agree on precisely
        | which product a code names, without two copies of that rule to
        | keep in sync.
        */
        $exact = $this->scanner->lookup($term);

        if ($exact) {
            return ApiResponse::success('', [
                'exact' => $exact,
                'results' => [$exact],
            ]);
        }

        $products = Product::query()
            // Sellable, not merely active: this box is how things get onto a
            // bill, and flour is not one of them.
            ->sellable()
            ->search($term)
            ->with(['unit', 'taxRate'])
            ->orderBy('name')
            ->limit(25)
            ->get();

        return ApiResponse::success('', [
            'exact' => null,
            'results' => $products->map(fn (Product $p) => $this->lookupRow($p, $shopId))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    private function lookupRow(Product $product, ?int $shopId): array
    {
        // One definition, on the model - a resumed held sale builds rows from
        // the same shape. See Product::toLookupArray.
        return $product->toLookupArray($shopId);
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'products-'.now()->format('Y-m-d-His').'.csv';
        $shopId = CurrentShop::id();

        /*
         | The menu's own columns, and deliberately the same ones the import
         | reads - see MenuImportService::COLUMNS. Download the menu, change
         | forty prices in a spreadsheet, upload it: forty dishes are updated
         | rather than four hundred duplicated.
         |
         | The old jewellery-era column set (MRP, on-hand, published) went
         | with it. A count of stock cannot be imported anyway - stock moves
         | through receipts and adjustments, which is the only way it stays
         | reconcilable - and a column somebody edits that is silently ignored
         | is worse than no column.
         */
        $columns = MenuImportService::COLUMNS;

        $query = $this->filtered($request)
            ->with(['category.parent', 'brand', 'unit', 'taxRate', 'kitchenStation']);

        ActivityLog::record('product.exported', 'Exported the menu');

        $menu = $this->menu;

        return response()->streamDownload(function () use ($query, $columns, $shopId, $menu) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(300, function ($chunk) use ($handle, $shopId, $menu) {
                foreach ($chunk as $product) {
                    fputcsv($handle, $menu->row($product, $shopId));
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------- bulk import */

    /** The upload form, with a link to a template that matches it exactly. */
    public function importForm(): View
    {
        return view('admin.products._import', [
            'columns' => MenuImportService::COLUMNS,
            'maxRows' => MenuImportService::MAX_ROWS,
        ]);
    }

    /**
     * A template: the headers, and one row of the shop's own menu as an
     * example where there is one.
     *
     * An empty template leaves somebody guessing what "Food Type" wants. A
     * real row answers it.
     */
    public function template(): StreamedResponse
    {
        $sample = Product::query()->sellable()
            ->with(['category.parent', 'brand', 'unit', 'taxRate', 'kitchenStation'])
            ->orderBy('name')
            ->first();

        $menu = $this->menu;
        $shopId = CurrentShop::id();

        return response()->streamDownload(function () use ($sample, $menu, $shopId) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, MenuImportService::COLUMNS);

            if ($sample !== null) {
                fputcsv($handle, $menu->row($sample, $shopId));
            }

            fclose($handle);
        }, 'menu-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Import a file, or explain why none of it went in.
     *
     * `preview` validates and reports without writing anything, which is what
     * anybody sane does first with four hundred rows.
     */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
            'preview' => ['nullable', 'boolean'],
        ], [
            'file.mimes' => 'Upload a .csv file. Save it as CSV from Excel first.',
        ]);

        $preview = (bool) ($data['preview'] ?? false);

        try {
            $result = $this->menu->import($request->file('file')->getRealPath(), $preview);
        } catch (RuntimeException $e) {
            // The service's message is a list of row numbers and what is wrong
            // with each, written for somebody looking at the spreadsheet.
            return ApiResponse::error($e->getMessage());
        }

        $made = $result['categories'] === []
            ? ''
            : sprintf(
                ' New categories: %s.',
                implode(', ', array_slice($result['categories'], 0, 8))
                    .(count($result['categories']) > 8 ? '…' : ''),
            );

        return ApiResponse::success(
            $preview
                ? sprintf(
                    '%d row(s) read: %d would be added, %d updated. Nothing has been changed yet.',
                    $result['rows'],
                    $result['created'],
                    $result['updated'],
                )
                : sprintf(
                    '%d dish(es) added, %d updated.%s',
                    $result['created'],
                    $result['updated'],
                    $made,
                ),
            $result,
        );
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Write, or clear, this shop's price override.
     *
     * A blank field means "inherit", so it is stored as NULL rather than as
     * zero - and a row where every field inherits is deleted outright, which
     * keeps a central price change reaching that shop.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncOverride(Product $product, array $data): void
    {
        $shop = CurrentShop::get();

        if ($shop === null || ! array_key_exists('override', $data)) {
            return;
        }

        $fields = ['purchase_price', 'mrp', 'selling_price', 'discount_percent', 'min_stock', 'reorder_level'];

        $values = [];

        foreach ($fields as $field) {
            $raw = $data['override'][$field] ?? null;
            $values[$field] = ($raw === null || $raw === '') ? null : (float) $raw;
        }

        $values['is_active'] = (bool) ($data['override']['is_active'] ?? true);

        $inheritsEverything = collect($fields)->every(fn (string $f) => $values[$f] === null);

        if ($inheritsEverything && $values['is_active']) {
            $product->shops()->detach($shop->id);

            return;
        }

        $product->shops()->syncWithoutDetaching([$shop->id => $values]);
    }

    /**
     * Add newly uploaded gallery images, respecting the cap.
     */
    private function storeGallery(Request $request, Product $product): void
    {
        $files = $request->file('gallery', []);

        if (empty($files)) {
            return;
        }

        $existing = $product->images()->count();
        $room = max(0, self::MAX_GALLERY - $existing);

        foreach (array_slice($files, 0, $room) as $index => $file) {
            $product->images()->create([
                'path' => $this->storeImage($file),
                'sort_order' => $existing + $index,
            ]);
        }
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $imageRules = [
            'image',
            'mimes:jpg,jpeg,png,webp',
            'mimetypes:image/jpeg,image/png,image/webp',
            'max:3072',
            'dimensions:min_width=100,min_height=100,max_width=5000,max_height=5000',
        ];

        return $request->validate([
            'name' => ['required', 'string', 'max:190'],

            'sku' => [
                'nullable', 'string', 'max:60',
                'regex:/^[A-Za-z0-9][A-Za-z0-9_\-\/]*$/',
                Rule::unique('products', 'sku')->whereNull('deleted_at')->ignore($product?->id),
            ],

            /*
             | Unique across every shop, including withdrawn products: a
             | scanner must never be able to resolve one code to two things.
             */
            'barcode' => [
                'nullable', 'string', 'max:60',
                'regex:/^[A-Za-z0-9\-]+$/',
                Rule::unique('products', 'barcode')->whereNull('deleted_at')->ignore($product?->id),
            ],

            'slug' => [
                'nullable', 'string', 'max:210',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('products', 'slug')->whereNull('deleted_at')->ignore($product?->id),
            ],

            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'kitchen_station_id' => ['nullable', 'integer', 'exists:kitchen_stations,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'],

            'hsn_code' => ['nullable', 'string', 'max:20'],
            'manufacturer' => ['nullable', 'string', 'max:150'],

            'purchase_price' => ['nullable', 'numeric', 'between:0,99999999999'],
            'mrp' => ['nullable', 'numeric', 'between:0,99999999999'],
            'selling_price' => ['required', 'numeric', 'between:0,99999999999'],
            'discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'tax_inclusive' => ['boolean'],

            'min_stock' => ['nullable', 'numeric', 'between:0,99999999'],
            'reorder_level' => ['nullable', 'numeric', 'between:0,99999999'],
            'track_batches' => ['boolean'],
            'is_made_to_order' => ['boolean'],
            'is_ingredient' => ['boolean'],

            /* ----------------------------------------------- menu (§8) */

            'food_type' => ['nullable', 'string', Rule::in(array_keys(Product::FOOD_TYPES))],
            'spice_level' => ['nullable', 'integer', Rule::in(array_keys(Product::SPICE_LEVELS))],
            // Typed as a comma-separated line and stored as an array; see
            // attributes() for where it is split.
            'food_tags' => ['nullable', 'string', 'max:300'],
            'serves' => ['nullable', 'integer', 'between:1,50'],
            'prep_minutes' => ['nullable', 'integer', 'between:0,600'],

            'dine_in_price' => ['nullable', 'numeric', 'between:0,99999999'],
            'takeaway_price' => ['nullable', 'numeric', 'between:0,99999999'],
            'delivery_price' => ['nullable', 'numeric', 'between:0,99999999'],

            'is_sold_out' => ['boolean'],
            'sold_out_until' => ['nullable', 'date'],

            /*
             | No `after:available_from` on the closing time, deliberately: a
             | late-night menu runs 23:00 to 02:00 and the model honours a
             | window that crosses midnight. Rejecting it here would make a
             | real case unenterable.
             */
            'available_from' => ['nullable', 'date_format:H:i'],
            'available_to' => ['nullable', 'date_format:H:i'],
            'available_days' => ['nullable', 'array', 'max:7'],
            'available_days.*' => ['integer', 'between:1,7'],

            /*
             | The size rows. Named `options` because they share the repeating
             | -row control with the add-on answers - one piece of JS, one
             | markup contract, and the server knows which it is from the
             | endpoint it arrived at.
             |
             | Rows with no name are dropped rather than refused: the form
             | always renders one empty row to type into, and a dish with no
             | sizes would otherwise be unsaveable.
             */
            'options' => ['nullable', 'array', 'max:20'],
            'options.*.name' => ['nullable', 'string', 'max:60'],
            'options.*.price' => ['nullable', 'numeric', 'between:0,99999999'],
            'options.*.sku' => ['nullable', 'string', 'max:60'],
            'options.*.is_default' => ['nullable', 'boolean'],
            'options.*.is_available' => ['nullable', 'boolean'],

            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],

            'is_active' => ['boolean'],
            'is_published' => ['boolean'],
            'is_featured' => ['boolean'],

            'meta_title' => ['nullable', 'string', 'max:190'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'meta_keywords' => ['nullable', 'string', 'max:300'],

            'sort_order' => ['nullable', 'integer', 'between:0,65535'],

            'image' => array_merge(['nullable'], $imageRules),
            'gallery' => ['nullable', 'array', 'max:'.self::MAX_GALLERY],
            'gallery.*' => $imageRules,

            // Per-shop overrides. Every one may be blank, meaning "inherit".
            'override' => ['nullable', 'array'],
            'override.purchase_price' => ['nullable', 'numeric', 'between:0,99999999999'],
            'override.mrp' => ['nullable', 'numeric', 'between:0,99999999999'],
            'override.selling_price' => ['nullable', 'numeric', 'between:0,99999999999'],
            'override.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'override.min_stock' => ['nullable', 'numeric', 'between:0,99999999'],
            'override.reorder_level' => ['nullable', 'numeric', 'between:0,99999999'],
            'override.is_active' => ['boolean'],
        ], [
            'sku.regex' => 'Use letters, numbers, hyphens, underscores or slashes.',
            'sku.unique' => 'Another product already uses that SKU.',
            'barcode.regex' => 'A barcode may contain letters, numbers and hyphens.',
            'barcode.unique' => 'Another product already uses that barcode â€” a scan has to be unambiguous.',
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'unit_id.required' => 'Choose a unit â€” the counter cannot bill without one.',
            'gallery.max' => 'Up to '.self::MAX_GALLERY.' gallery images.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $active = (bool) ($data['is_active'] ?? true);

        return [
            'name' => $data['name'],
            /*
             | Blank means "no barcode", not "empty string": the column is
             | unique, and MySQL treats repeated empty strings as duplicates
             | while it lets repeated NULLs through. A shop with two
             | un-barcoded products is normal.
             */
            'barcode' => filled($data['barcode'] ?? null) ? trim($data['barcode']) : null,
            'category_id' => $data['category_id'] ?? null,
            'kitchen_station_id' => $data['kitchen_station_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'unit_id' => $data['unit_id'],
            'tax_rate_id' => $data['tax_rate_id'] ?? null,
            'hsn_code' => $data['hsn_code'] ?? null,
            'manufacturer' => $data['manufacturer'] ?? null,

            'purchase_price' => (float) ($data['purchase_price'] ?? 0),
            'mrp' => (float) ($data['mrp'] ?? 0),
            'selling_price' => (float) $data['selling_price'],
            'discount_percent' => (float) ($data['discount_percent'] ?? 0),
            'tax_inclusive' => (bool) ($data['tax_inclusive'] ?? true),

            'min_stock' => (float) ($data['min_stock'] ?? 0),
            'reorder_level' => (float) ($data['reorder_level'] ?? 0),
            'track_batches' => (bool) ($data['track_batches'] ?? false),
            'is_made_to_order' => (bool) ($data['is_made_to_order'] ?? false),
            'is_ingredient' => (bool) ($data['is_ingredient'] ?? false),

            // Absent and empty both mean "not food". The `?? null` is what
            // makes an API caller that omits the field behave the same as a
            // form that submits it blank.
            'food_type' => ($data['food_type'] ?? null) ?: null,
            'spice_level' => (int) ($data['spice_level'] ?? 0),
            'food_tags' => $this->tags($data['food_tags'] ?? null),
            'serves' => $data['serves'] ?? null,
            'prep_minutes' => $data['prep_minutes'] ?? null,

            /*
             | Empty means "same as the shelf price", which is why these stay
             | null rather than becoming 0. A typed zero is a real decision to
             | give it away on that channel and is kept.
             */
            'dine_in_price' => $this->price($data['dine_in_price'] ?? null),
            'takeaway_price' => $this->price($data['takeaway_price'] ?? null),
            'delivery_price' => $this->price($data['delivery_price'] ?? null),

            'is_sold_out' => (bool) ($data['is_sold_out'] ?? false),
            // A "back on at" with the flag cleared is leftover from a form
            // that has just been un-ticked, and keeping it would put a stale
            // date on a dish that is being served.
            'sold_out_until' => (bool) ($data['is_sold_out'] ?? false)
                ? ($data['sold_out_until'] ?? null)
                : null,

            'available_from' => $data['available_from'] ?? null,
            'available_to' => $data['available_to'] ?? null,
            // Every day ticked is the same as none, and storing seven rows to
            // mean "always" would have the model run a pointless check.
            'available_days' => $this->days($data['available_days'] ?? null),

            'short_description' => $data['short_description'] ?? null,
            'description' => $data['description'] ?? null,

            'is_active' => $active,
            // An inactive product cannot be in the shop window: publishing
            // something nobody may sell is a broken link waiting to happen.
            'is_published' => $active && (bool) ($data['is_published'] ?? false),
            'is_featured' => (bool) ($data['is_featured'] ?? false),

            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,

            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    private function storeImage(UploadedFile $file): string
    {
        return $file->store(self::IMAGE_DIR, 'public');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'is_active' => $product->is_active,
            'is_published' => $product->is_published,
            'image' => $product->imageUrl(),
        ];
    }

    /**
     * Write the sizes for one dish (§8).
     *
     * Matched on name, so editing a price keeps the row - and with it any
     * report that has counted sales of a "Full". Rows the form no longer
     * carries are deleted: a size taken off the menu was a correction, and
     * past order lines copied the name and price they were charged at.
     *
     * Empty rows are skipped rather than refused. The form always renders one
     * to type into, and a dish that comes one way must stay saveable.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncVariants(Product $product, array $rows): void
    {
        $keep = [];
        $order = 0;
        $default = null;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $variant = $product->variants()->updateOrCreate(
                ['name' => $name],
                [
                    'price' => (float) ($row['price'] ?? 0),
                    'sku' => filled($row['sku'] ?? null) ? trim((string) $row['sku']) : null,
                    'is_default' => false,
                    'is_available' => (bool) ($row['is_available'] ?? true),
                    'sort_order' => $order++,
                ],
            );

            $keep[] = $variant->id;

            /*
             | The last row ticked wins, and exactly one ends up flagged. A
             | form with three boxes ticked is a user who did not realise it
             | was a radio; refusing it would be pedantry, and honouring all
             | three would leave the menu opening on a size at random.
             */
            if ((bool) ($row['is_default'] ?? false)) {
                $default = $variant;
            }
        }

        $product->variants()->whereNotIn('id', $keep)->delete();

        if ($default !== null) {
            $default->makeDefault();

            return;
        }

        /*
         | Nothing ticked, but sizes exist: the first one opens the menu.
         | Leaving none flagged would show the guest a dish with no size
         | selected and a price of nothing.
         */
        if ($keep !== [] && ! $product->variants()->where('is_default', true)->exists()) {
            $product->variants()->orderBy('sort_order')->first()?->makeDefault();
        }
    }

    /* ------------------------------------------------------ menu helpers */

    /**
     * "Chef's special, Contains nuts" -> ['Chef\'s special', 'Contains nuts']
     *
     * Empty pieces dropped and the rest de-duplicated, so a trailing comma or
     * a double one does not put a blank chip on the menu card.
     *
     * @return array<int, string>|null
     */
    private function tags(?string $raw): ?array
    {
        $tags = collect(explode(',', (string) $raw))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        return $tags === [] ? null : $tags;
    }

    /** A blank channel price is "same as the shelf"; a typed 0 is not. */
    private function price(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * @param  array<int, mixed>|null  $days
     * @return array<int, int>|null
     */
    private function days(?array $days): ?array
    {
        $days = collect($days ?? [])->map(fn ($d) => (int) $d)->unique()->sort()->values();

        return ($days->isEmpty() || $days->count() === 7) ? null : $days->all();
    }
}
