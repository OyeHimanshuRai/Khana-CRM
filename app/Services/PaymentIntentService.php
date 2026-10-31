<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Payment;
use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\TableSession;
use App\Services\Payments\GatewayManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Taking money online (§11).
 *
 * ---------------------------------------------------------------------------
 * One capture, two ways in
 * ---------------------------------------------------------------------------
 *
 * A guest's browser tells us they paid, and so does the provider's webhook.
 * Both are real and either can arrive first - a phone dies between paying and
 * the redirect, or a webhook beats the redirect by a second on a fast network.
 *
 * So both call `capture()`, it holds a row lock, and it does nothing the
 * second time. That is the whole design: the browser is a fast path, the
 * webhook is the record, and settling twice is the failure neither may cause.
 *
 * ---------------------------------------------------------------------------
 * Paying settles the table
 * ---------------------------------------------------------------------------
 *
 * A guest who pays has finished. Holding the money as a credit against the
 * sitting and asking the counter to settle later would mean a table that is
 * paid for but still shows as owing - and a cashier ringing it up again.
 *
 * So a captured payment goes straight through TableBillService::settle() with
 * the provider's reference on it, which is the same path the counter uses and
 * therefore the same invoice, the same GST and the same ledger.
 */
class PaymentIntentService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly TableBillService $bills,
    ) {}

    /* ------------------------------------------------------------ opening */

    /**
     * Start, or resume, an attempt to pay for something.
     *
     * An open intent is handed back rather than replaced. A guest who tapped
     * Pay, went to fetch their card and came back should meet the same
     * provider order - a new one every tap would leave a trail of abandoned
     * orders in the provider's dashboard and make reconciliation a guess.
     */
    public function open(Model $payable, float $amount, ?int $shopId = null): PaymentIntent
    {
        $gateway = $this->gateways->gateway();

        if (! $gateway->isConfigured()) {
            throw new RuntimeException('This outlet does not take online payment. Please pay at the counter.');
        }

        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('There is nothing left to pay.');
        }

        $existing = PaymentIntent::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->open()
            ->where('amount', $amount)
            ->where('provider', $gateway->key())
            ->latest('id')
            ->first();

        if ($existing !== null && filled($existing->provider_order_id)) {
            return $existing;
        }

        $intent = new PaymentIntent([
            'shop_id' => $shopId ?? $payable->getAttribute('shop_id'),
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'reference' => PaymentIntent::freshReference(),
            'provider' => $gateway->key(),
            'amount' => $amount,
            'currency' => 'INR',
            'status' => PaymentIntent::PENDING,
            'expires_at' => now()->addMinutes((int) config('payments.intent_ttl_minutes', 30)),
        ]);

        $intent->save();

        $opened = $gateway->open($intent);

        $intent->forceFill([
            'provider_order_id' => $opened['provider_order_id'] ?? null,
            'payload' => $opened['checkout'] ?? null,
        ])->save();

        return $intent->refresh();
    }

    /** What the browser needs to show a checkout, or null when there is none. */
    public function checkoutFor(PaymentIntent $intent): ?array
    {
        return $intent->payload ?: null;
    }

    /* ---------------------------------------------------------- capturing */

    /**
     * The browser says it paid. Check, then capture.
     *
     * The signature is the whole of the check. A status field in the payload
     * is ignored: a browser can send any JSON it likes, and the only thing it
     * cannot forge is an HMAC of a secret it does not have.
     *
     * @param  array<string, mixed>  $payload
     */
    public function confirmFromBrowser(PaymentIntent $intent, array $payload): PaymentIntent
    {
        $gateway = $this->gateways->gateway();

        if (! $gateway->verify($intent, $payload)) {
            $this->fail($intent, 'The payment could not be verified.');

            throw new RuntimeException('That payment could not be verified. Nothing has been charged twice — please ask a member of staff.');
        }

        return $this->capture(
            $intent,
            (string) ($payload['razorpay_payment_id'] ?? $payload['payment_id'] ?? ''),
            'browser',
        );
    }

    /**
     * The provider says it paid. This is the record.
     *
     * Returns null for anything it will not act on - an unverified signature,
     * an event this system ignores, an intent it cannot find. The caller
     * answers 200 to all of them: a webhook endpoint that returns an error to
     * a provider gets retried for a day.
     *
     * @param  array<string, string>  $headers
     */
    public function confirmFromWebhook(string $body, array $headers): ?PaymentIntent
    {
        $parsed = $this->gateways->gateway()->parseWebhook($body, $headers);

        if ($parsed === null || ! $parsed['paid']) {
            return null;
        }

        /*
         | Found by the provider's order id, without the shop scope: a webhook
         | arrives with no session, no user and no chosen branch, and the
         | intent knows which shop it belongs to perfectly well on its own.
         */
        $intent = PaymentIntent::allShops()
            ->where('provider_order_id', $parsed['provider_order_id'])
            ->latest('id')
            ->first();

        if ($intent === null) {
            return null;
        }

        /*
         | The amount has to match what we asked for. A webhook claiming a
         | two-rupee payment against a nine-hundred-rupee bill is either a
         | misconfiguration or an attack, and settling on it would give a
         | table away.
         */
        if (abs($parsed['amount'] - (float) $intent->amount) > 0.009) {
            report(new RuntimeException(sprintf(
                'Webhook amount %s does not match intent %s (%s).',
                $parsed['amount'],
                $intent->reference,
                $intent->amount,
            )));

            return null;
        }

        return $this->capture($intent, $parsed['provider_payment_id'], 'webhook');
    }

    /**
     * Mark it paid and do what being paid means, exactly once.
     *
     * Everything about this method is the second call: the lock, the re-read,
     * the early return. The browser and the webhook race on every single
     * payment, and a table settled twice is two invoices, two GST entries and
     * a guest charged again.
     */
    public function capture(PaymentIntent $intent, string $providerPaymentId, string $via = 'browser'): PaymentIntent
    {
        return DB::transaction(function () use ($intent, $providerPaymentId, $via) {
            /** @var PaymentIntent|null $locked */
            $locked = PaymentIntent::allShops()->whereKey($intent->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new RuntimeException('That payment attempt no longer exists.');
            }

            if ($locked->isPaid()) {
                // The other route got here first. Nothing to do, and that is
                // the point.
                return $locked;
            }

            $locked->forceFill([
                'status' => PaymentIntent::PAID,
                'provider_payment_id' => $providerPaymentId,
                'paid_at' => now(),
                'failure_reason' => null,
            ])->save();

            $this->settleWhatWasPaidFor($locked, $via);

            ActivityLog::record(
                'payment.captured',
                sprintf(
                    '%s paid online — ₹%s (%s, via the %s)',
                    $locked->reference,
                    number_format((float) $locked->amount, 2),
                    $providerPaymentId,
                    $via,
                ),
                $locked,
            );

            return $locked->refresh();
        });
    }

    /**
     * What a captured payment actually does.
     *
     * A table's sitting is settled through the same service the counter uses,
     * so the invoice, the GST split and the ledger are identical whether a
     * guest paid from their phone or a cashier rang it up.
     *
     * A failure here is logged and swallowed. The money has been taken; a
     * screen that threw would leave the guest thinking the payment failed and
     * paying again, which is the one outcome worse than an unsettled table.
     */
    private function settleWhatWasPaidFor(PaymentIntent $intent, string $via): void
    {
        $payable = $intent->payable;

        if (! $payable instanceof TableSession) {
            return;
        }

        try {
            $this->bills->settle($payable, [
                'payments' => [[
                    'method' => Payment::UPI,
                    'amount' => (float) $intent->amount,
                    'transaction_ref' => $intent->provider_payment_id,
                ]],
                'notes' => sprintf('Paid online — %s (%s)', $intent->reference, $via),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /* ------------------------------------------------------------ failing */

    public function fail(PaymentIntent $intent, string $reason): PaymentIntent
    {
        if ($intent->isPaid()) {
            // A paid intent is never walked back by a late failure message.
            return $intent;
        }

        $intent->forceFill([
            'status' => PaymentIntent::FAILED,
            'failure_reason' => $reason,
        ])->save();

        return $intent->refresh();
    }

    /* --------------------------------------------------------- refunding */

    /**
     * How much of this payment has not been given back yet (§11).
     *
     * Computed from the refund rows rather than stored, for the same reason
     * the loyalty balance is: a figure that can drift from the rows behind it
     * is worse than one query.
     *
     * Failed refunds do not count. Money that never left the account is money
     * the guest is still owed, and counting it would quietly shrink their
     * entitlement every time the provider had a bad afternoon.
     */
    public function refundableAmount(PaymentIntent $intent): float
    {
        if ($intent->status !== PaymentIntent::PAID && $intent->status !== PaymentIntent::REFUNDED) {
            return 0.0;
        }

        $given = (float) PaymentRefund::query()
            ->where('payment_intent_id', $intent->id)
            ->counted()
            ->sum('amount');

        return max(0.0, round((float) $intent->amount - $given, 2));
    }

    /**
     * Send money back.
     *
     * ----------------------------------------------------------------------
     * The row is written before the provider is called
     * ----------------------------------------------------------------------
     *
     * Deliberately, and it is the whole shape of this method. If the request
     * times out - the commonest real failure - the money may or may not have
     * left. A row written afterwards would not exist in the one case that
     * matters, and somebody would refund the guest a second time.
     *
     * So a `pending` row goes down first, the provider is called, and the row
     * is then marked sent or failed. Worst case a person has a row to
     * investigate, which is the right worst case.
     *
     * @throws RuntimeException with a message written for whoever pressed it
     */
    public function refund(PaymentIntent $intent, float $amount, ?string $reason = null): PaymentRefund
    {
        if (blank(trim((string) $reason))) {
            throw new RuntimeException('Say why. A refund nobody explained is the line an auditor stops at.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('A refund has to be for more than nothing.');
        }

        $available = $this->refundableAmount($intent);

        if ($available <= 0) {
            throw new RuntimeException($intent->status === PaymentIntent::PAID
                ? 'Every rupee of this payment has already been refunded.'
                : 'This payment was never captured, so there is nothing to send back.');
        }

        if ($amount > $available + 0.009) {
            throw new RuntimeException(sprintf(
                'Only %s of this payment is left to refund.',
                number_format($available, 2),
            ));
        }

        $refund = DB::transaction(function () use ($intent, $amount, $reason) {
            // Locked so two people pressing at once cannot both pass the
            // check above and refund the same money twice.
            $locked = PaymentIntent::query()->whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($this->refundableAmount($locked) + 0.009 < $amount) {
                throw new RuntimeException('Somebody else refunded this while you were looking at it.');
            }

            $row = PaymentRefund::create([
                'shop_id' => $locked->shop_id,
                'payment_intent_id' => $locked->id,
                'reference' => 'pending',
                'amount' => $amount,
                'currency' => $locked->currency,
                'reason' => $reason,
                'status' => PaymentRefund::PENDING,
                'user_id' => Auth::id(),
            ]);

            $row->forceFill([
                'reference' => 'RFD-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
            ])->save();

            return $row;
        });

        try {
            $result = $this->gateways->gateway()->refund($intent, $amount, $reason);

            $refund->forceFill([
                'provider_refund_id' => $result['provider_refund_id'],
                'status' => ($result['status'] ?? '') === 'failed'
                    ? PaymentRefund::FAILED
                    : PaymentRefund::PROCESSED,
                'refunded_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $refund->forceFill([
                'status' => PaymentRefund::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 255),
            ])->save();

            report($e);

            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        /*
         | The intent's status summarises its refunds. Only `refunded` once
         | nothing is left - a part refund leaves a payment that is still,
         | mostly, paid, and calling it refunded would make every report of
         | takings wrong.
         */
        if ($this->refundableAmount($intent->fresh()) <= 0.009) {
            $intent->forceFill(['status' => PaymentIntent::REFUNDED])->save();
        }

        return $refund->refresh();
    }
}
