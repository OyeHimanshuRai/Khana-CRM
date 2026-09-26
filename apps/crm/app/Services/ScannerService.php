<?php

namespace App\Services;

use App\Models\Product;
use App\Repositories\ProductRepository;
use App\Support\CurrentShop;
use App\Support\ScannerSettings;

/**
 * The one place "a scan turned into a product" happens.
 *
 * POST /admin/api/scanner/product (ScannerController) is its only caller
 * today, but the point of pulling this out of the controller is that it
 * is not allowed to stay that way: Purchase, Stock Transfer, Stock
 * Adjustment, Sales Return and Purchase Return all want the exact same
 * "code in, priced product out" behaviour, and none of them should have to
 * re-derive shop pricing or re-read the scanner settings to get it.
 *
 * Deliberately unaware of HTTP - it returns arrays and null, never a
 * response. The controller decides what a "not found" or "disabled" looks
 * like on the wire.
 */
class ScannerService
{
    public function __construct(private ProductRepository $products)
    {
        //
    }

    /**
     * Whether scanning is switched on at all.
     *
     * Checked before every lookup so a shop that has turned the scanner off
     * (Settings > Company Settings > Barcode Scanner) gets a clear "disabled"
     * answer instead of a lookup that quietly always misses.
     *
     * The master switch only - deliberately not Scanner Mode. Mode decides
     * which input methods a *screen* offers; this endpoint is shared by all
     * of them and has no way to tell which one produced the string it was
     * handed, so refusing on mode here would mean guessing.
     */
    public function isEnabled(): bool
    {
        return ScannerSettings::enabled();
    }

    /**
     * Resolve a scanned/typed code to the product the counter needs.
     *
     * The shape returned is a superset of the spec's minimal contract
     * (id, name, sku, barcode, mrp, selling_price, tax_rate, image): it also
     * carries unit, allow_decimal, average_cost, on_hand and available,
     * because those are what public/assets/js/line-items.js needs to render
     * a cart row and enforce whole-unit quantities. One shape, so the
     * camera scanner can hand its result straight to LineItems.add() with
     * no translation step.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $code): ?array
    {
        $code = trim($code);

        // Centralised here rather than only in ScannerController, so the
        // "Barcode Scanner" master switch also stops an exact barcode/SKU
        // match from auto-resolving through the generic product search box
        // (ProductController::lookup) - not just through the dedicated
        // camera endpoint. Free-text search by name is unaffected either
        // way; only the "this code names exactly one product" shortcut is.
        if ($code === '' || ! $this->isEnabled()) {
            return null;
        }

        $product = $this->products->findByCode($code);

        return $product ? $this->present($product) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Product $product): array
    {
        $shopId = CurrentShop::id();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'unit' => $product->unit?->code,
            'allow_decimal' => (bool) $product->unit?->allow_decimal,
            'mrp' => $product->mrpFor($shopId),
            'selling_price' => $product->counterPriceFor($shopId),
            // The cart's Rate column prefills from this on a purchase-side
            // screen; on POS it is unused but costs nothing to include.
            'average_cost' => $product->purchasePriceFor($shopId),
            'tax_rate' => (float) ($product->taxRate?->rate ?? 0),
            'on_hand' => $product->stockOnHand($shopId),
            'available' => $product->availableStock($shopId),
            'image' => $product->imageUrl(),
        ];
    }
}
