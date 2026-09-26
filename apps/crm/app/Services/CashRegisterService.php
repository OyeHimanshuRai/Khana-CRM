<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashRegister;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Shop;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opening, closing and approving a day's cash register.
 *
 * "Expected cash" is read from the same ledger everything else in the shop
 * already posts to - Payment, for cash actually taken or handed back, and
 * approved cash Expense rows, for cash spent out of the till on something
 * that never became a Payment row. Nothing here moves money; this is a
 * reconciliation against what already happened, not a third place that
 * could disagree with the other two about it.
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
     * took or gave back through the payments ledger, minus cash spent out
     * of the till on an approved expense.
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

        $expenses = (float) Expense::allShops()
            ->where('shop_id', $shopId)
            ->where('method', Payment::CASH)
            ->where('status', Expense::APPROVED)
            ->whereDate('spent_on', $date)
            ->sum('amount');

        return round((float) $register->opening_float + $in - $out - $expenses, 2);
    }
}
