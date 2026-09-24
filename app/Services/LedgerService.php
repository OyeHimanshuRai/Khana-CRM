<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The customer account.
 *
 * Same shape as StockService and for the same reason: two things have to
 * stay true of every balance in the system.
 *
 *   1. customers.balance always equals the last ledger entry's balance_after.
 *   2. No balance ever changes without a ledger row saying why.
 *
 * So there is one door. Nothing outside this class writes customers.balance,
 * and nothing at all writes customer_ledgers.
 *
 * Every entry is appended under a row lock on the customer, because two
 * counters billing the same farmer at once is ordinary at a busy shop, and
 * a running balance computed from a stale read is silently wrong for as
 * long as nobody reconciles.
 */
class LedgerService
{
    /**
     * Add to what the customer owes.
     */
    public function debit(
        Customer $customer,
        float $amount,
        string $type,
        string $description,
        ?Model $reference = null,
        ?\DateTimeInterface $at = null,
    ): CustomerLedger {
        return $this->record($customer, $type, $description, $amount, 0, $reference, $at);
    }

    /**
     * Reduce what the customer owes.
     */
    public function credit(
        Customer $customer,
        float $amount,
        string $type,
        string $description,
        ?Model $reference = null,
        ?\DateTimeInterface $at = null,
    ): CustomerLedger {
        return $this->record($customer, $type, $description, 0, $amount, $reference, $at);
    }

    /**
     * Reverse an entry that should not have been made.
     *
     * A mirror entry rather than a deletion: "this invoice was cancelled" is
     * something the statement should say, not something it should be unable
     * to show.
     */
    public function reverse(CustomerLedger $entry, string $description): CustomerLedger
    {
        $customer = $entry->customer;

        return $this->record(
            $customer,
            CustomerLedger::ADJUSTMENT,
            $description,
            (float) $entry->credit,
            (float) $entry->debit,
            $entry->reference,
        );
    }

    /**
     * Write off an outstanding balance.
     *
     * Its own method because it is its own decision - the SRS gives it a
     * separate permission, and an audit will look for it by name.
     */
    public function writeOff(Customer $customer, float $amount, string $reason): CustomerLedger
    {
        return $this->record(
            $customer,
            CustomerLedger::WRITE_OFF,
            'Written off: '.$reason,
            0,
            $amount,
            null,
        );
    }

    /* ------------------------------------------------------------ reading */

    /**
     * Recompute a customer's balance from the ledger and repair the cache.
     *
     * The cache should never be wrong. This exists for the case where it is
     * - a crashed import, a hand-edited row - and for the reconciliation
     * screen that proves it is not.
     *
     * @return array{stored: float, actual: float, drift: float}
     */
    public function reconcile(Customer $customer, bool $repair = false): array
    {
        $actual = (float) CustomerLedger::allShops()
            ->where('customer_id', $customer->id)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as total')
            ->value('total');

        $stored = (float) $customer->balance;
        $drift = round($actual - $stored, 2);

        if ($repair && abs($drift) > 0.004) {
            $customer->forceFill(['balance' => $actual])->save();
        }

        return ['stored' => $stored, 'actual' => $actual, 'drift' => $drift];
    }

    /* ----------------------------------------------------------- internal */

    /**
     * The one write path.
     *
     * @param  float  $debit   increases the debt
     * @param  float  $credit  reduces it
     */
    private function record(
        Customer $customer,
        string $type,
        string $description,
        float $debit,
        float $credit,
        ?Model $reference,
        ?\DateTimeInterface $at = null,
    ): CustomerLedger {
        return DB::transaction(function () use ($customer, $type, $description, $debit, $credit, $reference, $at) {
            /** @var Customer $locked */
            $locked = Customer::allShops()->lockForUpdate()->findOrFail($customer->id);

            $balance = round((float) $locked->balance + $debit - $credit, 2);

            $user = Auth::user();

            $entry = CustomerLedger::query()->create([
                'shop_id' => $locked->shop_id,
                'customer_id' => $locked->id,
                'type' => $type,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'balance_after' => $balance,
                'entered_at' => $at ?? now(),
                'user_id' => $user?->id,
                'user_name' => $user?->name ?? 'System',
            ]);

            $locked->forceFill(['balance' => $balance])->save();

            // Keep the caller's instance honest; it is usually about to be
            // rendered.
            $customer->balance = $balance;

            return $entry;
        });
    }
}
