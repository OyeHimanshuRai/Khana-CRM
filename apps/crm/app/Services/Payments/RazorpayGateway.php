<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Razorpay (§11).
 *
 * Wired first because UPI intent, cards and netbanking arrive through one
 * integration, which is what a QR-ordering restaurant in India actually needs.
 *
 * ---------------------------------------------------------------------------
 * Paise, not rupees
 * ---------------------------------------------------------------------------
 *
 * Every amount crossing this boundary is an integer number of paise. Sending
 * 290.00 where 29000 is meant is a bill for two rupees ninety, and it is the
 * single commonest mistake in an Indian payment integration - so the
 * conversion happens in exactly one place, on the way out, and the way back is
 * checked against it.
 *
 * ---------------------------------------------------------------------------
 * Two secrets, and they are not interchangeable
 * ---------------------------------------------------------------------------
 *
 * The API secret signs the checkout callback; a *separate* webhook secret
 * signs the webhook body. Using the API secret to check a webhook is the
 * commonest way a webhook silently never verifies, and the failure looks
 * exactly like "the provider never called us".
 */
class RazorpayGateway implements PaymentGateway
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function key(): string
    {
        return 'razorpay';
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? 'Pay now');
    }

    public function isConfigured(): bool
    {
        return filled($this->config['key'] ?? null) && filled($this->config['secret'] ?? null);
    }

    /* -------------------------------------------------------------- opening */

    /**
     * Open an order with Razorpay and hand the browser what it needs.
     *
     * @return array<string, mixed>
     */
    public function open(PaymentIntent $intent): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Online payment is not set up for this outlet.');
        }

        $response = Http::withBasicAuth($this->config['key'], $this->config['secret'])
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->post($this->config['api'].'/orders', [
                'amount' => $this->paise($intent->amount),
                'currency' => $this->config['currency'] ?? 'INR',
                /*
                 | Our own reference, echoed back on every event. It is how a
                 | webhook that arrives before - or instead of - the browser
                 | callback finds the intent it belongs to.
                 */
                'receipt' => $intent->reference,
                'notes' => [
                    'intent' => (string) $intent->id,
                    'shop' => (string) $intent->shop_id,
                ],
            ]);

        if (! $response->successful()) {
            /*
             | The provider's own message is deliberately not shown to a
             | guest: it is written for a developer and sometimes names
             | internal fields. It goes to the log; the guest gets a sentence
             | they can act on.
             */
            report(new RuntimeException('Razorpay refused an order: '.$response->body()));

            throw new RuntimeException('The payment could not be started. Please try again, or pay at the counter.');
        }

        $order = $response->json();

        return [
            'provider_order_id' => $order['id'] ?? null,
            // Everything below is handed to the view as-is, so nothing
            // provider-specific ends up in a Blade file.
            'checkout' => [
                'script' => $this->config['checkout_js'],
                'key' => $this->config['key'],
                'order_id' => $order['id'] ?? null,
                'amount' => $this->paise($intent->amount),
                'currency' => $this->config['currency'] ?? 'INR',
            ],
        ];
    }

    /* ------------------------------------------------------------ verifying */

    /**
     * Check the browser's claim against a signature it cannot forge.
     *
     * Razorpay signs `order_id|payment_id` with the API secret. A status
     * field in the payload is ignored entirely - a browser can send any JSON
     * it likes, and the only thing it cannot make up is an HMAC of a secret
     * it does not have.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verify(PaymentIntent $intent, array $payload): bool
    {
        $orderId = (string) ($payload['razorpay_order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['razorpay_signature'] ?? '');

        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }

        /*
         | The order id has to be the one *this* intent opened. Without it a
         | guest could replay another table's valid callback and settle their
         | own bill with somebody else's payment.
         */
        if ($orderId !== $intent->provider_order_id) {
            return false;
        }

        $expected = hash_hmac('sha256', $orderId.'|'.$paymentId, (string) $this->config['secret']);

        // Constant time: a naive === on a signature leaks its bytes to
        // anybody patient enough to measure.
        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{provider_payment_id: string, provider_order_id: string, paid: bool, amount: float}|null
     */
    public function parseWebhook(string $body, array $headers): ?array
    {
        $secret = $this->config['webhook_secret'] ?? null;

        if (blank($secret)) {
            return null;
        }

        $signature = $this->header($headers, 'x-razorpay-signature');

        if ($signature === null) {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', $body, (string) $secret), $signature)) {
            return null;
        }

        $event = json_decode($body, true);

        if (! is_array($event)) {
            return null;
        }

        /*
         | Only the one event this system acts on. `payment.failed` and the
         | refund events are deliberately ignored here rather than half
         | handled: a failure is already the intent's default state, and a
         | refund is a decision somebody makes on this side, not a thing that
         | happens to us.
         */
        if (($event['event'] ?? null) !== 'payment.captured') {
            return null;
        }

        $payment = $event['payload']['payment']['entity'] ?? null;

        if (! is_array($payment)) {
            return null;
        }

        return [
            'provider_payment_id' => (string) ($payment['id'] ?? ''),
            'provider_order_id' => (string) ($payment['order_id'] ?? ''),
            'paid' => ($payment['status'] ?? null) === 'captured',
            // Back into rupees, the only place that conversion happens.
            'amount' => round(((int) ($payment['amount'] ?? 0)) / 100, 2),
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Send money back through Razorpay.
     *
     * Refunded against the PAYMENT, never the order. An order is a request;
     * the payment is the money, and it is the only thing Razorpay can take
     * anything out of. An intent with no provider payment id was never
     * actually paid, whatever its status column says.
     *
     * `speed: normal` on purpose. Instant refunds cost the restaurant a fee
     * and land in minutes; normal is free and lands in days. A restaurant
     * that wants to pay for speed should choose that deliberately rather
     * than discover it on a statement.
     *
     * @return array{provider_refund_id: string, status: string}
     */
    public function refund(PaymentIntent $intent, float $amount, ?string $reason = null): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Online payment is not set up for this outlet.');
        }

        if (blank($intent->provider_payment_id)) {
            throw new RuntimeException(
                'There is no payment on this to refund - it was never captured by the provider.'
            );
        }

        $response = Http::withBasicAuth($this->config['key'], $this->config['secret'])
            ->asJson()
            ->acceptJson()
            ->timeout(20)
            ->post($this->config['api'].'/payments/'.$intent->provider_payment_id.'/refund', [
                'amount' => $this->paise($amount),
                'speed' => 'normal',
                'notes' => array_filter([
                    'intent' => (string) $intent->id,
                    'reason' => $reason,
                ]),
            ]);

        if (! $response->successful()) {
            /*
             | The provider's own wording is passed through. A refund refused
             | for "insufficient balance in your account" is something the
             | restaurant has to act on, and "the refund failed" would send
             | them looking in the wrong place.
             */
            throw new RuntimeException(sprintf(
                'Razorpay refused the refund (%d): %s',
                $response->status(),
                data_get($response->json(), 'error.description', 'no reason given'),
            ));
        }

        $body = $response->json();

        $id = data_get($body, 'id');

        if (blank($id)) {
            throw new RuntimeException('Razorpay accepted the refund but did not say which one it is.');
        }

        return [
            'provider_refund_id' => (string) $id,
            // processed | pending | failed
            'status' => (string) data_get($body, 'status', 'pending'),
        ];
    }

    /**
     * Rupees to paise.
     *
     * Rounded rather than cast: (int) (290.00 * 100) is 28999 on a machine
     * that stored 289.99999999, and a bill one paisa short is refused by the
     * provider with a message nobody can act on.
     */
    private function paise(float|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }
}
