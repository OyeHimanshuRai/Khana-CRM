<?php

namespace App\Services;

use App\Models\OutletSetting;
use App\Models\SalesAddon;
use App\Models\SalesItem;
use App\Services\Crm\CrmClient;
use App\Services\Crm\CrmUnavailable;
use App\Support\Outlets;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Tenancy\Entitlements;
use RuntimeException;

/**
 * ERP's copy of the menu, brought in line with CRM's in one go.
 *
 * Day to day the menu arrives an item at a time, by event (MenuCopy). This
 * is for the three moments that is not enough: the first time an outlet
 * holds both products, when ERP has years of menu to catch up on; the
 * nightly check, which is how a lost menu event is found; and a person
 * pressing "Pull the menu from CRM" because they would rather not wait.
 *
 * CRM's whole menu is fetched first, and only then is anything written:
 *
 *   - an item CRM has on its menu is taken in exactly as its event would
 *     have been - through MenuCopy, the same door;
 *   - an item CRM has archived is archived here, if ERP has it;
 *   - an item from CRM that CRM no longer lists at all is archived too.
 *
 * Nothing is deleted. An archived dish keeps its recipe and its sales, and
 * comes back the moment CRM lists it again.
 *
 * The counts handed back are of what actually differed. A pull that found
 * ERP already right says so with three zeros.
 */
class MenuSync
{
    public function __construct(
        private readonly CrmClient $crm,
        private readonly MenuCopy $menu,
        private readonly Availability $availability,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @return array{added: int, changed: int, archived: int}
     *
     * @throws CrmUnavailable
     */
    public function pull(string $outletId): array
    {
        if (! $this->entitlements->isSuite($outletId)) {
            throw new RuntimeException('Only an outlet with both Khana CRM and Khana ERP has a menu in CRM to pull.');
        }

        $tenantId = Outlets::tenantOf($outletId);

        // Asked before the transaction opens, never inside it.
        $items = $this->crm->menu($outletId);

        $counts = DB::transaction(function () use ($tenantId, $outletId, $items) {
            $counts = ['added' => 0, 'changed' => 0, 'archived' => 0];
            $listed = [];

            foreach ($items as $data) {
                $listed[] = $data['item_id'];
                $held = $this->held($outletId, $data['item_id']);

                if ($data['archived'] ?? false) {
                    // One ERP never had is not worth making just to put away.
                    if ($held !== null && $held->archived_at === null) {
                        $this->menu->archive($outletId, $data['item_id']);
                        $counts['archived']++;
                    }

                    continue;
                }

                $before = $held === null ? null : $this->picture($held, $data);

                $this->menu->upsert($tenantId, $outletId, $data);

                if ($before === null) {
                    $counts['added']++;
                } elseif ($this->picture($this->held($outletId, $data['item_id']), $data) !== $before) {
                    $counts['changed']++;
                }
            }

            $gone = SalesItem::allOutlets()
                ->where('outlet_id', $outletId)->where('source', SalesItem::CRM)
                ->whereNull('archived_at')
                ->whereNotIn('ref', $listed)
                ->pluck('ref');

            foreach ($gone as $ref) {
                $this->menu->archive($outletId, $ref);
                $counts['archived']++;
            }

            return $counts;
        });

        // A dish that came, went or changed size may now be sold out, or
        // back on sale: look at every one again and tell CRM what changed.
        $this->availability->recheck($outletId);

        OutletSetting::query()->updateOrCreate(['outlet_id' => $outletId], ['tenant_id' => $tenantId, 'menu_synced_at' => now()]);

        return $counts;
    }

    /**
     * The counts, as a sentence a person reads after pressing the button
     * or on the nightly check's page.
     *
     * @param  array{added: int, changed: int, archived: int}  $counts
     */
    public static function describe(array $counts): string
    {
        $parts = [];

        foreach (['added' => 'added', 'changed' => 'changed', 'archived' => 'put away'] as $key => $word) {
            if ($counts[$key] > 0) {
                $parts[] = $counts[$key].' '.($counts[$key] === 1 ? 'dish' : 'dishes').' '.$word;
            }
        }

        return $parts === [] ? 'ERP already had the menu as CRM has it.' : 'Menu brought in line with CRM: '.implode(', ', $parts).'.';
    }

    /* ---------------------------------------------------------- private */

    private function held(string $outletId, string $itemRef): ?SalesItem
    {
        return SalesItem::allOutlets()->with('sizes')
            ->where('outlet_id', $outletId)->where('source', SalesItem::CRM)->where('ref', $itemRef)->first();
    }

    /**
     * Everything ERP holds about one menu item that CRM has a say in, in a
     * form two of which can be compared: the item, its sizes, and the
     * add-ons CRM lists with it. Taken before and after the item goes
     * through MenuCopy, so "changed" means ERP's copy really is different -
     * not that CRM sent it again.
     *
     * @param  array<string, mixed>  $data  CRM's item, for the add-ons it names
     * @return array<string, mixed>
     */
    private function picture(SalesItem $item, array $data): array
    {
        $addons = SalesAddon::allOutlets()
            ->where('outlet_id', $item->outlet_id)->where('source', SalesItem::CRM)
            ->whereIn('ref', array_column($data['addons'] ?? [], 'option_id'))
            ->orderBy('ref')
            ->get();

        return [
            'item' => [$item->name, $item->category, $item->sku, $item->barcode, $item->price_paise, $item->is_active, $item->archived_at !== null],
            'sizes' => $item->sizes->sortBy('ref')->map(
                fn ($size) => [$size->ref, $size->name, $size->sku, $size->barcode, $size->price_paise, $size->is_default, $size->is_active],
            )->values()->all(),
            'addons' => $addons->map(
                fn (SalesAddon $addon) => [$addon->ref, $addon->group_name, $addon->name, $addon->price_paise, $addon->is_active],
            )->all(),
        ];
    }
}
