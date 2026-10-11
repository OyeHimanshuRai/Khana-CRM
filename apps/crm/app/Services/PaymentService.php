<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Collecting money after the sale.
 *
 * Three things happen together and must not come apart: the payment is
 * recorded, the invoices it settles are marked down, and the customer's
 * ledger moves. One transaction, one door.
 *
 * A payment is never edited. Clearing a cheque, bouncing one and reversing a
 * mistake are each their own operation leaving their own trace, because
 * "the amount changed" is not something a shop's books should ever be able
 * to say.
 */
class PaymentService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AlertService $alerts,
    ) {}

    /**
     * Take money from a customer.
     *
     * Applied oldest-invoice-first unless the caller names the invoices,
     * which is what a customer handing over a lump sum actually means - and
     * what keeps the ageing report honest.
     *
     * @param  array<int, float>|null  $allocations  invoice id => amount;
     *                                               null allocates oldest first
     */
    public function collect(
        Customer $customer,
        float $amount,
        string $method,
        array $details = [],
        ?array $allocations = null,
    ): Payment {
        if ($amount <= 0) {
            throw new RuntimeException('A payment has to be for more than zero.');
        }

        $shop = Shop::withTrashed()->findOrFail($customer->shop_id);

        return DB::transaction(function () use ($customer, $amount, $method, $details, $allocations, $shop) {
            $payment = $this->record($shop, $customer, $amount, Payment::IN, $method, $details);

            /*
             | A cheque changes nothing until it clears. It is on the record
             | from the moment it is taken - the customer has a receipt - but
             | the balance and the invoices stay where they were.
             */
            if (! $payment->isEffective()) {
                ActivityLog::record(
                    'payment.received_pending',
                    sprintf('Took %s of ₹%s from %s, pending clearance',
                        $payment->methodLabel(), number_format($amount, 2), $customer->name),
                    $payment,
                );

                // Raised for the pending one too, and the body says so: a
                // cheque in the drawer is worth knowing about even though it
                // has settled nothing (SRS 15).
                $this->alerts->paymentReceived($payment);

                return $payment;
            }

            $this->apply($payment, $customer, $amount, $allocations);

            $this->alerts->paymentReceived($payment);

            return $payment;
        });
    }

    /**
     * Mark a pending payment - in practice a cheque - as cleared.
     *
     * Only now does it touch the balance, which is the whole reason pending
     * exists as a state.
     */
    public function clear(Payment $payment, ?array $allocations = null): Payment
    {
        if ($payment->status !== Payment::PENDING) {
            throw new RuntimeException('Only a pending payment can be cleared.');
        }

        return DB::transaction(function () use ($payment, $allocations) {
            $payment->forceFill(['status' => Payment::CLEARED])->save();

            $customer = $payment->party instanceof Customer ? $payment->party : null;

            if ($customer) {
                $this->apply($payment, $customer, (float) $payment->amount, $allocations);
            }

            ActivityLog::record(
                'payment.cleared',
                "Cleared {$payment->number} · ₹".number_format((float) $payment->amount, 2),
                $payment,
            );

            return $payment;
        });
    }

    /**
     * A cheque that did not clear.
     *
     * The debt comes back, and so does the invoice's outstanding amount. The
     * payment row stays - a bounced cheque is a fact about the customer that
     * the shop wants to remember.
     */
    public function bounce(Payment $payment, string $reason): Payment
    {
        if (! in_array($payment->status, [Payment::PENDING, Payment::CLEARED], true)) {
            throw new RuntimeException('Only a pending or cleared payment can be marked as bounced.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            $wasEffective = $payment->isEffective();

            $payment->forceFill([
                'status' => Payment::BOUNCED,
                'notes' => trim(($payment->notes ? $payment->notes."\n" : '').'Bounced: '.$reason),
            ])->save();

            $customer = $payment->party instanceof Customer ? $payment->party : null;

            // Only a payment that had already reduced the balance needs
            // putting back; a pending one never did.
            if ($customer && $wasEffective) {
                $this->unapply($payment, $customer, $reason);
            }

            ActivityLog::record(
                'payment.bounced',
                "Payment {$payment->number} bounced: {$reason}",
                $payment,
            );

            return $payment;
        });
    }

    /**
     * Undo a payment that should not have been recorded.
     *
     * A reversing payment pointing at the original, never an edit. The pair
     * nets to nothing and both stay visible, which is what makes the
     * correction auditable rather than invisible.
     */
    public function reverse(Payment $payment, string $reason): Payment
    {
        if ($payment->status === Payment::CANCELLED) {
            throw new RuntimeException('That payment has already been reversed.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('Reversing a payment needs a reason.');
        }

        return DB::transaction(function () use ($payment, $reason) {
            $shop = Shop::withTrashed()->findOrFail($payment->shop_id);
            $wasEffective = $payment->isEffective();

            $reversal = Payment::query()->create([
                'shop_id' => $shop->id,
                'number' => Payment::nextNumber($shop, $payment->direction === Payment::IN ? Payment::OUT : Payment::IN),
                // The mirror direction: money that came in goes back out.
                'direction' => $payment->direction === Payment::IN ? Payment::OUT : Payment::IN,
                'method' => $payment->method,
                'party_type' => $payment->party_type,
                'party_id' => $payment->party_id,
                'party_name' => $payment->party_name,
                'reference_type' => $payment->reference_type,
                'reference_id' => $payment->reference_id,
                'amount' => $payment->amount,
                'paid_at' => now(),
                'status' => Payment::CLEARED,
                'notes' => 'Reverses '.$payment->number.': '.$reason,
                'reverses_payment_id' => $payment->id,
            ]);

            $user = Auth::user();
            $reversal->forceFill([
                'created_by' => $user?->id,
                'created_by_name' => $user?->name ?? 'System',
            ])->save();

            $payment->forceFill([
                'status' => Payment::CANCELLED,
                'notes' => trim(($payment->notes ? $payment->notes."\n" : '')
                    .'Reversed by '.$reversal->number.': '.$reason),
            ])->save();

            $customer = $payment->party instanceof Customer ? $payment->party : null;

            if ($customer && $wasEffective) {
                $this->unapply($payment, $customer, $reason);
            }

            ActivityLog::record(
                'payment.reversed',
                "Reversed {$payment->number} with {$reversal->number}: {$reason}",
                $reversal,
            );

            return $reversal;
        });
    }

    /* ----------------------------------------------------------- internal */

    /**
     * Write the payment row.
     *
     * @param  array<string, mixed>  $details
     */
    private function record(
        Shop $shop,
        Customer $customer,
        float $amount,
        string $direction,
        string $method,
        array $details,
    ): Payment {
        $payment = Payment::query()->create([
            'shop_id' => $shop->id,
            'number' => Payment::nextNumber($shop, $direction),
            'direction' => $direction,
            'method' => $method,
            'party_type' => $customer::class,
            'party_id' => $customer->id,
            'party_name' => $customer->name,
            'amount' => round($amount, 2),
            'paid_at' => $details['paid_at'] ?? now(),
            'transaction_ref' => $details['transaction_ref'] ?? null,
            'bank_name' => $details['bank_name'] ?? null,
            'cheque_date' => $details['cheque_date'] ?? null,
            'status' => Payment::initialStatus($method),
            'notes' => $details['notes'] ?? null,
        ]);

        $user = Auth::user();

        $payment->forceFill([
            'created_by' => $user?->id,
            'created_by_name' => $user?->name ?? 'System',
        ])->save();

        return $payment;
    }

    /**
     * Put a cleared payment against the customer's invoices and ledger.
     *
     * @param  array<int, float>|null  $allocations
     */
    private function apply(Payment $payment, Customer $customer, float $amount, ?array $allocations): void
    {
        $settled = $this->settleInvoices($customer, $amount, $allocations, $payment);

        $this->ledger->credit(
            $customer,
            $amount,
            CustomerLedger::PAYMENT,
            $this->describe($payment, $settled),
            $payment,
            $payment->paid_at,
        );

        ActivityLog::record(
            'payment.received',
            sprintf('Received ₹%s from %s by %s (%s)',
                number_format($amount, 2),
                $customer->name,
                $payment->methodLabel(),
                $payment->number,
            ),
            $payment,
            ['settled' => $settled->pluck('number')->all()],
        );
    }

    /**
     * Take a payment back off the invoices and the ledger.
     */
    private function unapply(Payment $payment, Customer $customer, string $reason): void
    {
        $amount = (float) $payment->amount;

        /*
         | Restored newest-first, the mirror of how it was applied. The order
         | matters: a bounced cheque should reopen the most recently settled
         | invoices, leaving the oldest debt where the ageing report already
         | put it.
         */
        $remaining = $amount;

        $invoices = Invoice::allShops()
            ->where('customer_id', $customer->id)
            ->counted()
            ->where('paid_total', '>', 0)
            ->orderByDesc('invoiced_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0.004) {
                break;
            }

            $take = min($remaining, (float) $invoice->paid_total);
            $paid = round((float) $invoice->paid_total - $take, 2);

            $invoice->forceFill([
                'paid_total' => $paid,
                'due_total' => round((float) $invoice->grand_total - $paid, 2),
                'status' => $invoice->statusForPaid($paid),
            ])->save();

            $remaining -= $take;
        }

        $this->ledger->debit(
            $customer,
            $amount,
            CustomerLedger::ADJUSTMENT,
            'Reversed '.$payment->number.': '.$reason,
            $payment,
        );
    }

    /**
     * Spread a payment across outstanding invoices.
     *
     * Oldest first by default, which is both what a customer means by
     * "here's five thousand towards my account" and what keeps the ageing
     * buckets meaningful.
     *
     * @param  array<int, float>|null  $allocations
     * @return Collection<int, Invoice>
     */
    private function settleInvoices(
        Customer $customer,
        float $amount,
        ?array $allocations,
        Payment $payment,
    ): Collection {
        $settled = collect();
        $remaining = round($amount, 2);

        $query = Invoice::allShops()
            ->where('customer_id', $customer->id)
            ->outstanding();

        $invoices = $allocations === null
            ? $query->orderBy('invoiced_at')->orderBy('id')->lockForUpdate()->get()
            : $query->whereIn('id', array_keys($allocations))->lockForUpdate()->get();

        foreach ($invoices as $invoice) {
            if ($remaining <= 0.004) {
                break;
            }

            $wanted = $allocations === null
                ? $remaining
                : round((float) ($allocations[$invoice->id] ?? 0), 2);

            $apply = min($wanted, (float) $invoice->due_total, $remaining);

            if ($apply <= 0.004) {
                continue;
            }

            $paid = round((float) $invoice->paid_total + $apply, 2);

            $invoice->forceFill([
                'paid_total' => $paid,
                'due_total' => round((float) $invoice->grand_total - $paid, 2),
                'status' => $invoice->statusForPaid($paid),
            ])->save();

            $settled->push($invoice);
            $remaining = round($remaining - $apply, 2);
        }

        /*
         | Anything left over stays on the account as an advance. That is a
         | real thing a farmer does before a season, and refusing it would
         | send them away with money in hand.
         */
        if ($remaining > 0.004) {
            $payment->forceFill([
                'notes' => trim(($payment->notes ? $payment->notes."\n" : '')
                    .'₹'.number_format($remaining, 2).' left on account as an advance.'),
            ])->save();
        }

        return $settled;
    }

    /**
     * @param  Collection<int, Invoice>  $settled
     */
    private function describe(Payment $payment, Collection $settled): string
    {
        if ($settled->isEmpty()) {
            return $payment->methodLabel().' received on account · '.$payment->number;
        }

        if ($settled->count() === 1) {
            return $payment->methodLabel().' against '.$settled->first()->number;
        }

        return $payment->methodLabel().' against '.$settled->count().' invoices · '.$payment->number;
    }
}
