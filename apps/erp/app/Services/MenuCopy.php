<?php

namespace App\Services;

use App\Models\SalesAddon;
use App\Models\SalesItem;
use App\Models\SalesItemSize;
use Illuminate\Support\Facades\DB;

/**
 * ERP's copy of the menu.
 *
 * CRM owns what guests order. Each time a menu item is saved there, the
 * whole item arrives here - its sizes, its add-on options, this outlet's
 * prices - and replaces what ERP had. A new dish then shows under "Needs
 * recipe"; a packaged one whose barcode matches a stock item is linked on
 * the spot.
 *
 * The same shape is used for an outlet with no CRM: a CSV import or the
 * sales API makes sales items here as it meets names it has not seen.
 */
class MenuCopy
{
    public function __construct(private readonly Recipes $recipes) {}

    /**
     * @param  array<string, mixed>  $data  the `data` of crm.menu.item_upserted
     */
    public function upsert(string $tenantId, string $outletId, array $data): SalesItem
    {
        return DB::transaction(function () use ($tenantId, $outletId, $data) {
            $item = SalesItem::allOutlets()->updateOrCreate(
                ['outlet_id' => $outletId, 'source' => SalesItem::CRM, 'ref' => $data['item_id']],
                [
                    'tenant_id' => $tenantId,
                    'name' => $data['name'],
                    'category' => $data['category']['name'] ?? null,
                    'sku' => $data['sku'] ?? null,
                    'barcode' => $data['barcode'] ?? null,
                    'price_paise' => (int) $data['price_paise'],
                    'is_active' => (bool) $data['active'],
                    'archived_at' => null,
                ],
            );

            $seen = [];

            foreach ($data['sizes'] ?? [] as $size) {
                $seen[] = SalesItemSize::query()->updateOrCreate(
                    ['sales_item_id' => $item->id, 'ref' => $size['variant_id']],
                    [
                        'name' => $size['name'],
                        'sku' => $size['sku'] ?? null,
                        'barcode' => $size['barcode'] ?? null,
                        'price_paise' => (int) $size['price_paise'],
                        'is_default' => (bool) $size['is_default'],
                        'is_active' => true,
                    ],
                )->id;
            }

            // A size CRM no longer lists is switched off, not deleted: old
            // sales still name it, and its recipe may be wanted back.
            $item->sizes()->whereNotIn('id', $seen)->update(['is_active' => false]);

            foreach ($data['addons'] ?? [] as $addon) {
                SalesAddon::allOutlets()->updateOrCreate(
                    ['outlet_id' => $outletId, 'source' => SalesItem::CRM, 'ref' => $addon['option_id']],
                    [
                        'tenant_id' => $tenantId,
                        'group_name' => $addon['modifier_name'],
                        'name' => $addon['name'],
                        'price_paise' => (int) $addon['price_paise'],
                        'is_active' => (bool) $addon['active'],
                    ],
                );
            }

            $this->recipes->linkByBarcode($item->refresh());

            return $item;
        });
    }

    public function archive(string $outletId, string $itemRef): void
    {
        SalesItem::allOutlets()
            ->where('outlet_id', $outletId)->where('source', SalesItem::CRM)->where('ref', $itemRef)
            ->update(['is_active' => false, 'archived_at' => now()]);
    }

    /**
     * The sales item an outside name stands for, made if this is the first
     * time it has been seen (a CSV import, the sales API).
     *
     * @return array{0: SalesItem, 1: ?SalesItemSize}
     */
    public function outside(string $tenantId, string $outletId, string $source, string $itemRef, string $name, ?string $variantRef = null): array
    {
        $item = SalesItem::allOutlets()->firstOrCreate(
            ['outlet_id' => $outletId, 'source' => $source, 'ref' => $itemRef],
            ['tenant_id' => $tenantId, 'name' => $name],
        );

        $size = filled($variantRef)
            ? SalesItemSize::query()->firstOrCreate(
                ['sales_item_id' => $item->id, 'ref' => $variantRef],
                ['name' => $variantRef],
            )
            : null;

        return [$item, $size];
    }
}
