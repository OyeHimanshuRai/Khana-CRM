<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\CurrentShop;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Barcode/price sticker sheets for the shelf.
 *
 * Not a document - nothing is saved. A print run is built from the current
 * catalogue each time, from whichever products the shop picks and however
 * many copies each needs, and handed straight to the browser's print dialog.
 */
class BarcodeLabelController extends Controller
{
    /** @var array<string, array{label: string, width: string, height: string}> */
    public const SIZES = [
        'small' => ['label' => 'Small (40 × 20mm)', 'width' => '40mm', 'height' => '20mm'],
        'medium' => ['label' => 'Medium (50 × 30mm)', 'width' => '50mm', 'height' => '30mm'],
        'large' => ['label' => 'Large (65 × 40mm)', 'width' => '65mm', 'height' => '40mm'],
    ];

    public function index(): View
    {
        return view('admin.labels.index', [
            'sizes' => self::SIZES,
        ]);
    }

    public function print(Request $request): View
    {
        $data = $request->validate([
            'size' => ['required', 'string', Rule::in(array_keys(self::SIZES))],
            'show_name' => ['nullable', 'boolean'],
            'show_price' => ['nullable', 'boolean'],
            'show_mrp' => ['nullable', 'boolean'],
            'show_shop' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:500'],
        ], [
            'items.required' => 'Add at least one product to print labels for.',
        ]);

        $shopId = CurrentShop::id();
        $shop = CurrentShop::get();

        $products = Product::query()
            ->with(['unit', 'taxRate'])
            ->whereIn('id', collect($data['items'])->pluck('product_id'))
            ->get()
            ->keyBy('id');

        $generator = new BarcodeGeneratorSVG();

        /** @var array<int, array{product: Product, code: string, svg: string}> $labels */
        $labels = [];

        foreach ($data['items'] as $line) {
            $product = $products->get($line['product_id']);

            if ($product === null) {
                continue;
            }

            $code = $product->reference();

            if (blank($code)) {
                continue;
            }

            $svg = $generator->getBarcode($code, $generator::TYPE_CODE_128, 1.4, 34);

            for ($i = 0; $i < (int) $line['quantity']; $i++) {
                $labels[] = ['product' => $product, 'code' => $code, 'svg' => $svg];
            }
        }

        return view('admin.labels.print', [
            'labels' => $labels,
            'shop' => $shop,
            'size' => self::SIZES[$data['size']],
            'showName' => (bool) ($data['show_name'] ?? false),
            'showPrice' => (bool) ($data['show_price'] ?? false),
            'showMrp' => (bool) ($data['show_mrp'] ?? false),
            'showShop' => (bool) ($data['show_shop'] ?? false),
            'shopId' => $shopId,
        ]);
    }
}
