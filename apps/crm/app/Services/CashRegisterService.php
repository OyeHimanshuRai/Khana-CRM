<?php

namespace App\Services;

use App\Events\Outbound\CrmEvents;
use App\Events\Outbound\PaidOutPayload;
use App\Models\ActivityLog;
use App\Models\CashPaidOut;
use App\Models\CashRegister;
use App\Models\Payment;
use App\Models\Shop;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Ulid;
use RuntimeException;

/**
 * Opening, closing and approving a day's cash register.
 *
 * "Expected cash" is read from the same ledger everything else in the shop
 * already posts to - Payment, for cash actually taken or handed back, and
 * CashPaidOut, for cash spent out of the till on something that never
 * became a Payment row. Nothing here moves money; this is a reconciliation
 * against what already happened, not a third place that could disagree
 * with the other two about it.
 */
class CashRegisterService
{
    /**
     * Open today's register for a shop.
     *
     * Refused if one already exists for the day - reopening a closed
     * session is not how a mistake gets corrected; the approval step's
     * review note is.
     *
     * Also refused while an earlier day is still open. See below.
     */
    public function open(Shop $shop, float $openingFloat, ?CarbonInterface $businessDate = null): CashRegister
    {
        // Which day it is belongs to the outlet, not to the server. A till
        // opened at half past midnight in Jaipur is that day's till; on a UTC
        // clock it was filed under the day before and then refused as already
        // open when the morning shift tried again.
        $date = ($businessDate ?? BusinessDay::today($shop))->toDateString();

        if (CashRegister::forShop($shop->id)->where('business_date', $date)->exists()) {
            throw new RuntimeException("The register for {$date} has already been opened.");
        }

        $this->guardEarlierDay($shop, $date);

        $user = Auth::user();

        $register = CashRegister::query()->create([
            'shop_id' => $shop->id,
            'business_date' => $date,
            'status' => CashRegister::OPEN,
            'opening_float' => round($openingFloat, 2),
        ]);

        $register->forceFill([
            'opened_by' => $user?->id,
            'opened_by_name' => $user?->name ?? 'System',
            'opened_at' => now(),
        ])->save();

        ActivityLog::record(
            'cash_register.opened',
            sprintf('Opened the register for %s with ₹%s float', $date, number_format($openingFloat, 2)),
            $register,
        );

        return $register;
    }

    /**
     * Refuse a new day while an earlier one has never been closed.
     *
     * A register that was opened and never closed has no expected figure, no
     * counted figure and no variance - the day was simply never reconciled.
     * Stacking a new one on top of it means nobody ever will: the drawer has
     * moved on, the cash that was in it is gone, and the only honest thing
     * left to record about that day is that it was lost.
     *
     * Refused rather than warned, because a warning on this screen is a
     * warning during the morning rush, which is a warning nobody reads. The
     * stale day is one click away and closing it takes a moment.
     *
     * @throws RuntimeException
     */
    private function guardEarlierDay(Shop $shop, string $date): void
    {
        $stale = CashRegister::forShop($shop->id)
            ->where('status', CashRegister::OPEN)
            ->where('business_date', '<', $date)
            ->orderBy('business_date')
            ->first();

        if ($stale === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The register for %s is still open and was never counted. Close that day first — '
            .'opening a new one on top of it means %s can never be reconciled.',
            $stale->business_date->format('j M Y'),
            $stale->business_date->format('j M'),
        ));
    }

    /**
     * Declare the counted cash and see how it compares.
     */
    public function close(CashRegister $register, float $countedCash, ?string $notes = null): CashRegister
    {
        if (! $register->isOpen()) {
            throw new RuntimeException('That register has already been '.strtolower($register->statusLabel()).'.');
        }

        return DB::transaction(function () use ($register, $countedCash, $notes) {
            $expected = $this->expectedCash($register);
            $counted = round($countedCash, 2);
            $user = Auth::user();

            $register->forceFill([
                'status' => CashRegister::CLOSED,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => round($counted - $expected, 2),
                'notes' => $notes,
                'closed_by' => $user?->id,
                'closed_by_name' => $user?->name ?? 'System',
                'closed_at' => now(),
            ])->save();

            ActivityLog::record(
                'cash_register.closed',
                sprintf(
                    'Closed the register for %s: expected ₹%s, counted ₹%s',
                    $register->business_date->toDateString(),
                    number_format($expected, 2),
                    number_format($counted, 2),
                ),
                $register,
                ['variance' => (float) $register->variance],
            );

            return $register->refresh();
        });
    }

    /** Sign off on a closed day, variance and all. */
    public function approve(CashRegister $register, ?string $note = null): CashRegister
    {
        if (! $register->isClosed()) {
            throw new RuntimeException(
                $register->isOpen()
                    ? 'Close the register before approving it.'
                    : 'That register has already been approved.'
            );
        }

        $user = Auth::user();

        $register->forceFill([
            'status' => CashRegister::APPROVED,
            'approved_by' => $user?->id,
            'approved_by_name' => $user?->name ?? 'System',
            'approved_at' => now(),
            'review_note' => $note,
        ])->save();

        ActivityLog::record(
            'cash_register.approved',
            "Approved the register for {$register->business_date->toDateString()}",
            $register,
        );

        return $register;
    }

    /**
     * What should be in the drawer: the float, plus cash the shop actually
     * took or gave back through the payments ledger, minus cash paid out
     * of the till.
     */
    public function expectedCash(CashRegister $register): float
    {
        $shopId = $register->shop_id;
        $date = $register->business_date->toDateString();

        /*
         | paid_at is an instant, business_date is a day, and only the outlet
         | knows where one ends. whereDate() asks the database to truncate,
         | and the database does not know whose day it is - so a sale rung up
         | at 2am local landed in the previous day's drawer and the variance
         | stopped meaning anything. Half-open, so the last second of the day
         | is counted once.
         */
        [$from, $until] = BusinessDay::window($date, $register->shop);

        $in = (float) Payment::allShops()
            ->where('shop_id', $shopId)
            ->where('method', Payment::CASH)
            ->where('direction', Payment::IN)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until)
            ->sum('amount');

        $out = (float) Payment::allShops()
            ->where('shop_id', $shopId)
            ->where('method', Payment::CASH)
            ->where('direction', Payment::OUT)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until)
            ->sum('amount');

        $paidOut = (float) $this->paidOutsOn($register)->sum('amount');

        return round((float) $register->opening_float + $in - $out - $paidOut, 2);
    }

    /* --------------------------------------------------------- paid-outs */

    /**
     * What left the drawer on this register's day, oldest first.
     *
     * The very rows expectedCash() takes off, read through the same query,
     * so the list on the register's page and the figure above it cannot
     * disagree about which paid-outs belong to the day.
     *
     * @return Collection<int, CashPaidOut>
     */
    public function paidOuts(CashRegister $register): Collection
    {
        return $this->paidOutsOn($register)->orderBy('paid_at')->orderBy('id')->get();
    }

    /**
     * May cash be paid out against this register?
     *
     * Only the open register of the outlet's current day. A paid-out is
     * stamped with the moment it happened and counted into the day that
     * moment falls on, so one taken against a register left open from
     * yesterday would come off today's drawer: yesterday's expected figure
     * would not move, and the cashier would be right to think it had not
     * been recorded.
     */
    public function takesPaidOuts(CashRegister $register): bool
    {
        return $register->isOpen()
            && $register->business_date->toDateString() === BusinessDay::today($register->shop)->toDateString();
    }

    /**
     * Hand cash out of the drawer for something that is not a Payment - an
     * auto fare, milk from the shop across the road, a vendor paid in cash.
     *
     * The row and crm.cash.paid_out are written in one transaction: ERP books
     * the expense under `category` (a code such as "petty_cash" or
     * "supplier_payment"), and a paid-out that rolled back never reached it.
     * Recorded, never edited - see CashPaidOut - so a mistake is corrected by
     * a new row, and ERP hears of that one too.
     *
     * A paid-out may name the supplier bill it settles: ERP's id for it, and
     * the supplier and bill number as the cashier saw them. Nothing here
     * asks ERP whether the bill exists or what is owed on it. The drawer is
     * short of that cash whatever ERP thinks, and a till that could not
     * record it while ERP was down would be recording it on paper. ERP
     * matches the id when the event arrives.
     */
    public function payOut(
        Shop $shop,
        float $amount,
        string $reason,
        string $category = PaidOutPayload::DEFAULT_CATEGORY,
        ?CarbonInterface $paidAt = null,
        ?string $supplierBillId = null,
        ?string $supplierName = null,
        ?string $supplierBillNo = null,
    ): CashPaidOut {
        $amount = round($amount, 2);
        $reason = trim($reason);
        $bill = null;

        if ($amount < 0.01) {
            throw new RuntimeException('A paid-out needs an amount.');
        }

        if (filled($supplierBillId)) {
            if (! Ulid::isValid($supplierBillId)) {
                throw new RuntimeException('That is not a supplier bill from the list. Pick the bill again.');
            }

            $bill = [
                'supplier_bill_ulid' => $supplierBillId,
                'supplier_name' => filled($supplierName) ? mb_substr(trim($supplierName), 0, 190) : null,
                'supplier_bill_no' => filled($supplierBillNo) ? mb_substr(trim($supplierBillNo), 0, 100) : null,
            ];

            // Cash against a supplier's bill is stock bought, whatever else
            // was ticked: ERP files it as a payment on that bill either way,
            // and the day close should not call the same cash "Gas".
            $category = CashPaidOut::SUPPLIER_PAYMENT;

            // The bill is itself what the cash was for.
            if ($reason === '') {
                $reason = CashPaidOut::describeBill($bill['supplier_name'], $bill['supplier_bill_no']);
            }
        }

        if ($reason === '') {
            throw new RuntimeException('Say what the cash was for - the day close will ask.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $category) !== 1) {
            throw new RuntimeException('A paid-out category is a short code such as petty_cash.');
        }

        return DB::transaction(function () use ($shop, $amount, $reason, $category, $paidAt, $bill) {
            $user = Auth::user();

            $paidOut = CashPaidOut::allShops()->create([
                'shop_id' => $shop->id,
                'amount' => $amount,
                'category' => $category,
                'reason' => mb_substr($reason, 0, 190),
                'paid_at' => $paidAt ?? now(),
                'paid_by' => $user?->id,
                'paid_by_name' => $user?->name ?? 'System',
                ...($bill ?? []),
            ]);

            ActivityLog::record(
                'cash.paid_out',
                sprintf('Paid ₹%s out of the drawer: %s', number_format($amount, 2), $reason),
                $paidOut,
            );

            $this->events()->cashPaidOut($paidOut);

            return $paidOut;
        });
    }

    /**
     * The paid-outs that fall on a register's day, by the outlet's clock -
     * the same half-open window expectedCash() reads its payments through.
     *
     * @return Builder<CashPaidOut>
     */
    private function paidOutsOn(CashRegister $register): Builder
    {
        [$from, $until] = BusinessDay::window($register->business_date->toDateString(), $register->shop);

        return CashPaidOut::allShops()
            ->where('shop_id', $register->shop_id)
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until);
    }

    /*
     | Resolved when needed rather than injected, so the tests that build this
     | service with `new` keep working.
     */
    private function events(): CrmEvents
    {
        return app(CrmEvents::class);
    }
}
