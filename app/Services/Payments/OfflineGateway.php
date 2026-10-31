<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\PaymentIntent;
use RuntimeException;

/**
 * No online payment: everybody pays at the counter (§11).
 *
 * A real object rather than a null check scattered through the code. Every
 * caller has something to talk to, no screen has to ask "is payment switched
 * on" before it can render, and the one place that knows what "off" means is
 * `isConfigured()` returning false.
 *
 * It is also the default, which matters: a restaurant that has not set up a
 * provider is not broken, it is a restaurant that takes cash.
 */
class OfflineGateway implements PaymentGateway
{
    public function key(): string
    {
        return 'offline';
    }

    public function label(): string
    {
        return (string) config('payments.gateways.offline.label', 'Pay at the counter');
    }

    public function isConfigured(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function open(PaymentIntent $intent): array
    {
        /*
         | Thrown rather than returned empty. Reaching here means a screen
         | offered a Pay button that isConfigured() said should not exist, and
         | a silent empty array would leave a guest tapping a button that does
         | nothing rather than telling somebody the wiring is wrong.
         */
        throw new RuntimeException('This outlet does not take online payment. Please pay at the counter.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(PaymentIntent $intent, array $payload): bool
    {
        // Nothing this gateway issued can be verified, so nothing is.
        return false;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{provider_payment_id: string, provider_order_id: string, paid: bool, amount: float}|null
     */
    public function parseWebhook(string $body, array $headers): ?array
    {
        return null;
    }

    /**
     * There is nothing to send back.
     *
     * Money taken at the counter is given back at the counter, in cash or by
     * whatever the till did. Pretending otherwise would record a refund this
     * system never made.
     */
    public function refund(PaymentIntent $intent, float $amount, ?string $reason = null): array
    {
        throw new RuntimeException(
            'This payment was taken at the counter, so it has to be refunded at the counter. '
            .'Record it as a sales return instead.'
        );
    }
}
