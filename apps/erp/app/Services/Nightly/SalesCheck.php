<?php

namespace App\Services\Nightly;

use App\Models\Sale;
use App\Models\SalesItem;
use App\Providers\CrmLinkServiceProvider;
use App\Services\Consumption;
use App\Services\Crm\CrmClient;
use App\Services\Crm\CrmUnavailable;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Database\Eloquent\Builder;
use Khana\Kit\Tenancy\Entitlements;

/**
 * The nightly sales check: one trading day's bills as ERP holds them,
 * against the same day as CRM holds it.
 *
 * CRM says how many bills stand, what they add up to, which they are, and
 * which of the day's bills were cancelled. From that, in this order:
 *
 *   1. A bill CRM has and ERP never heard of is fetched and recorded,
 *      through the same Consumption the lost event would have gone through -
 *      so its stock moves exactly as it would have.
 *   2. A bill CRM cancelled and ERP still counts is cancelled here.
 *   3. A bill ERP holds for the day that CRM lists nowhere is a problem. It
 *      is named and left alone: ERP does not delete a sale on a guess.
 *   4. A dish on one of the day's bills that the kitchen never reported as
 *      cooked is booked - it was served.
 *   5. Then the two are counted again. If the number of bills or their
 *      total still differs, that is a problem, and both figures are given.
 *
 * Only for an outlet that holds both products. One that bills with
 * something else has nothing in CRM to compare with, and CRM is not asked.
 */
final class SalesCheck
{
    /** How many bills are named in the detail; the rest are counted. */
    private const NAMED = 5;

    public function __construct(
        private readonly CrmClient $crm,
        private readonly Consumption $consumption,
        private readonly Entitlements $entitlements,
    ) {}

    public function run(string $outletId, string $date): Finding
    {
        if (! $this->entitlements->isSuite($outletId)) {
            return Finding::skipped('This outlet does not use Khana CRM, so there are no CRM sales to compare.');
        }

        try {
            $digest = $this->crm->salesDigest($outletId, $date);
        } catch (CrmUnavailable $e) {
            return Finding::problem(0, 0, 'CRM could not be reached, so the day\'s sales were not compared. '.$e->getMessage().' ERP tries again every hour.');
        }

        $tenantId = Outlets::tenantOf($outletId);
        $found = 0;
        $repaired = 0;
        $done = [];
        $problems = [];

        /* 1. Bills CRM has that ERP never heard of. */

        $known = $this->sales($outletId)->whereIn('ref', $digest['sale_ids'])->pluck('ref')->all();
        $fetched = [];

        foreach (array_values(array_diff($digest['sale_ids'], $known)) as $saleId) {
            $found++;

            try {
                $sale = $this->crm->sale($saleId);
            } catch (CrmUnavailable $e) {
                $problems[] = 'CRM stopped answering part-way through: '.$e->getMessage();

                break;
            }

            if ($sale === null || $sale['outlet_id'] !== $outletId) {
                $problems[] = 'CRM lists a bill for the day that it could not hand over.';

                continue;
            }

            $this->consumption->saleRecorded($tenantId, $outletId, SalesItem::CRM, CrmLinkServiceProvider::sale($sale));

            // Cancelled in the moment between the list and the bill itself.
            if (($sale['status'] ?? 'issued') === 'cancelled') {
                $this->consumption->saleCancelled($outletId, SalesItem::CRM, $saleId, 'Cancelled in CRM; ERP heard of it from the nightly check');
            }

            $fetched[] = $sale['invoice_no'];
            $repaired++;
        }

        if ($fetched !== []) {
            $done[] = self::count($fetched, 'bill ERP had not heard of was', 'bills ERP had not heard of were')
                .' fetched from CRM and recorded ('.self::named($fetched).').';
        }

        /* 2. Bills CRM cancelled that ERP still counts. */

        $cancelled = [];

        foreach ($this->sales($outletId)->whereIn('ref', $digest['cancelled_sale_ids'])->where('status', Sale::RECORDED)->orderBy('id')->get() as $sale) {
            $found++;

            $this->consumption->saleCancelled($outletId, SalesItem::CRM, $sale->ref, 'Cancelled in CRM; ERP heard of it from the nightly check');

            $cancelled[] = $sale->invoice_no;
            $repaired++;
        }

        if ($cancelled !== []) {
            $done[] = self::count($cancelled, 'bill cancelled in CRM was', 'bills cancelled in CRM were').' cancelled here ('.self::named($cancelled).').';
        }

        /* 3. Bills ERP holds that CRM does not list, or lists differently. */

        $strangers = $this->day($outletId, $date)
            ->whereNotIn('ref', [...$digest['sale_ids'], ...$digest['cancelled_sale_ids']])
            ->orderBy('id')->pluck('invoice_no')->all();

        if ($strangers !== []) {
            $found += count($strangers);
            $problems[] = self::count($strangers, 'bill is', 'bills are').' in ERP for this day but not in CRM\'s list ('.self::named($strangers).'). Nothing was removed.';
        }

        $undone = $this->sales($outletId)->whereIn('ref', $digest['sale_ids'])->where('status', Sale::CANCELLED)->orderBy('id')->pluck('invoice_no')->all();

        if ($undone !== []) {
            $found += count($undone);
            $problems[] = self::count($undone, 'bill is', 'bills are').' cancelled in ERP but '.(count($undone) === 1 ? 'stands' : 'stand').' in CRM ('.self::named($undone).').';
        }

        /* 4. Dishes billed that the kitchen never reported as cooked. */

        $booked = 0;

        foreach ($this->day($outletId, $date)->where('status', Sale::RECORDED)->orderBy('id')->get() as $sale) {
            $booked += $this->consumption->bookBilledKitchenLines($sale);
        }

        if ($booked > 0) {
            $found += $booked;
            $repaired += $booked;
            $done[] = ($booked === 1 ? '1 dish that was billed but never marked cooked was' : $booked.' dishes that were billed but never marked cooked were').' booked as used.';
        }

        /* 5. Count again. */

        $ours = $this->day($outletId, $date)->where('status', Sale::RECORDED);
        $count = (clone $ours)->count();
        $total = (int) (clone $ours)->sum('grand_paise');

        $figures = fn (int $bills, int $paise) => $bills.' '.($bills === 1 ? 'bill' : 'bills').' worth ₹'.Units::rupees($paise);

        if ($count !== $digest['count'] || $total !== $digest['grand_paise']) {
            $problems[] = 'ERP has '.$figures($count, $total).' for the day; CRM has '.$figures($digest['count'], $digest['grand_paise']).'.';
        }

        if ($problems !== []) {
            return Finding::problem(max($found, 1), $repaired, implode(' ', [...$done, ...$problems]));
        }

        $agreed = 'ERP and CRM both have '.$figures($count, $total).' for the day.';

        return $found === 0
            ? Finding::ok($agreed)
            : Finding::repaired($repaired, implode(' ', [...$done, $agreed]));
    }

    /** The bills this outlet has from CRM. */
    private function sales(string $outletId): Builder
    {
        return Sale::allOutlets()->where('outlet_id', $outletId)->where('source', SalesItem::CRM);
    }

    /** ...and those of one trading day. */
    private function day(string $outletId, string $date): Builder
    {
        // Both ends given, because some databases keep a time beside the day.
        return $this->sales($outletId)->whereBetween('business_date', [$date, $date.' 23:59:59']);
    }

    /** @param  list<string>  $rows */
    private static function count(array $rows, string $one, string $many): string
    {
        return count($rows).' '.(count($rows) === 1 ? $one : $many);
    }

    /** @param  list<string>  $invoices */
    private static function named(array $invoices): string
    {
        $named = array_slice($invoices, 0, self::NAMED);

        return implode(', ', $named).(count($invoices) > count($named) ? ' and '.(count($invoices) - count($named)).' more' : '');
    }
}
