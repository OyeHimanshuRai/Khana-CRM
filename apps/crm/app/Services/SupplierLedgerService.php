<?php

namespace App\Services;

use App\Models\Supplier;
use App\Models\SupplierLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The supplier account.
 *
 * The mirror of LedgerService, with the signs the other way round: a bill
 * credits the supplier (the shop owes more), a payment debits them (owes
 * less). Same one-door rule - nothing outside this class writes
 * suppliers.balance, and nothing at all writes supplier_ledgers.
 */
class SupplierLedgerService
{
    /** The shop now owes the supplier more. */
    public function bill(
        Supplier $supplier,
        float $amount,
        string $description,
        ?Model $reference = null,
        ?\DateTimeInterface $at = null,
    ): SupplierLedger {
        return $this->record($supplier, SupplierLedger::BILL, $description, 0, $amount, $reference, $at);
    }

    /** The shop now owes the supplier less. */
    public function settle(
        Supplier $supplier,
        float $amount,
        string $description,
        string $type = SupplierLedger::PAYMENT,
        ?Model $reference = null,
        ?\DateTimeInterface $at = null,
    ): SupplierLedger {
        return $this->record($supplier, $type, $description, $amount, 0, $reference, $at);
    }

    /**
     * Undo an entry with its mirror.
     *
     * A cancelled bill should read as "cancelled" on the statement, not
     * disappear from it.
     */
    public function reverse(Supplier $supplier, SupplierLedger $entry, string $description): SupplierLedger
    {
        return $this->record(
            $supplier,
            SupplierLedger::ADJUSTMENT,
            $description,
            (float) $entry->credit,
            (float) $entry->debit,
            $entry->reference,
        );
    }

    /**
     * Recompute from the ledger and repair the cached balance.
     *
     * @return array{stored: float, actual: float, drift: float}
     */
    public function reconcile(Supplier $supplier, bool $repair = false): array
    {
        $actual = (float) SupplierLedger::allShops()
            ->where('supplier_id', $supplier->id)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as total')
            ->value('total');

        $stored = (float) $supplier->balance;
        $drift = round($actual - $stored, 2);

        if ($repair && abs($drift) > 0.004) {
            $supplier->forceFill(['balance' => $actual])->save();
        }

        return ['stored' => $stored, 'actual' => $actual, 'drift' => $drift];
    }

    /* ----------------------------------------------------------- internal */

    /**
     * The one write path, under a row lock on the supplier.
     */
    private function record(
        Supplier $supplier,
        string $type,
        string $description,
        float $debit,
        float $credit,
        ?Model $reference,
        ?\DateTimeInterface $at = null,
    ): SupplierLedger {
        return DB::transaction(function () use ($supplier, $type, $description, $debit, $credit, $reference, $at) {
            /** @var Supplier $locked */
            $locked = Supplier::allShops()->lockForUpdate()->findOrFail($supplier->id);

            // Positive means the shop is in debt to them, so a credit adds.
            $balance = round((float) $locked->balance + $credit - $debit, 2);

            $user = Auth::user();

            $entry = SupplierLedger::query()->create([
                'shop_id' => $locked->shop_id,
                'supplier_id' => $locked->id,
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

            $supplier->balance = $balance;

            return $entry;
        });
    }
}
