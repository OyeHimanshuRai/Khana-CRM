<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;

/**
 * Product lookups that exist to serve a scan, not a type-ahead.
 *
 * Product::scopeSearch() (see the model) already covers free-text search
 * across name/SKU/barcode/etc. for the generic line-item picker used
 * by POS, purchase orders, stock adjustments and the rest. This repository
 * is narrower on purpose: a scan is never a partial match, so it only ever
 * resolves the exact code a scanner or camera produced.
 */
class ProductRepository
{
    /**
     * The one product a barcode or SKU scan resolved to, or null.
     *
     * Which column(s) are checked is a setting (Settings > Company Settings >
     * Barcode Scanner > "Search By"), not a constant - a shop whose SKUs
     * collide with a supplier's barcode scheme can narrow the match to
     * barcode only.
     */
    public function findByCode(string $code): ?Product
    {
        $mode = Setting::get('scanner_search_by', 'barcode_sku');

        return Product::query()
            ->active()
            ->when(
                $mode === 'barcode',
                fn (Builder $q) => $q->where('barcode', $code),
                fn (Builder $q) => $mode === 'sku'
                    ? $q->where('sku', $code)
                    : $q->byBarcode($code)
            )
            ->with(['unit', 'taxRate'])
            ->first();
    }
}
