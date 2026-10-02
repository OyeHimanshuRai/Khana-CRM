<?php

namespace App\Services\Nightly;

use App\Models\Shop;
use App\Services\Erp\ErpClient;
use App\Services\Erp\ErpLink;
use App\Services\Erp\ErpUnavailable;
use App\Support\StockOuts;

/**
 * Does CRM's sold-out list for this outlet match what ERP last said?
 *
 * ERP tells CRM a dish ran out, or came back, with erp.availability.changed,
 * and CRM keeps a row per outlet for it (App\Support\StockOuts). An event
 * that was dropped leaves the two out of step - a dish ERP has taken off is
 * still on CRM's menu, or the other way about. This asks ERP for the whole
 * picture now (ErpClient::signals) and reconciles CRM's rows to it, so a lost
 * event is made good by morning rather than waiting for the next one.
 *
 * It reads, it does not order ERP about: the reconcile only writes CRM's own
 * rows, and nothing goes back to ERP.
 */
final class AvailabilityCheck
{
    public function __construct(
        private readonly ErpClient $erp,
        private readonly ErpLink $link,
    ) {}

    public function run(Shop $shop): Finding
    {
        if (! $this->link->linked($shop)) {
            return Finding::skipped('This outlet has no ERP to compare with, or this install has no address for it.');
        }

        try {
            $items = $this->erp->signals($shop->ulid);
        } catch (ErpUnavailable $e) {
            return Finding::problem(0, 0, 'ERP could not be reached, so sold-out was not checked. CRM tries again every hour. ('.$e->getMessage().')');
        }

        $counts = StockOuts::reconcile($shop, $items);
        $changed = $counts['added'] + $counts['removed'];

        if ($changed === 0) {
            return Finding::ok('Sold-out matches what ERP holds.');
        }

        return Finding::repaired($changed, self::said($counts));
    }

    /**
     * @param  array{added: int, removed: int}  $counts
     */
    private static function said(array $counts): string
    {
        $parts = [];

        if ($counts['added'] > 0) {
            $parts[] = $counts['added'].' '.($counts['added'] === 1 ? 'dish was' : 'dishes were').' taken off the menu';
        }

        if ($counts['removed'] > 0) {
            $parts[] = $counts['removed'].' '.($counts['removed'] === 1 ? 'dish was' : 'dishes were').' put back';
        }

        return 'To match ERP, '.implode(' and ', $parts).'.';
    }
}
