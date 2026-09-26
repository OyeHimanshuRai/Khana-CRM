<?php

namespace App\Contracts;

use App\Models\PaymentIntent;

/**
 * What this system needs from an online payment provider (§11).
 *
 * Five methods, and deliberately no more. Everything a provider offers beyond
 * these - saved cards, subscriptions, EMI, their own dashboard - is reached
 * through their own tools rather than mirrored here, because a restaurant that
 * changes provider should have to rewrite one class and nothing else.
 *
 * The two that matter are `verify` and `parseWebhook`, because they are the
 * only places where money and trust meet: everything else is a request, but
 * these two decide whether a claim that somebody paid is true.
 */
interface PaymentGateway
{
    /** The key this gateway is configured under. */
    public function key(): string;

    /** What a guest sees on the button. */
    public function label(): string;

    /**
     * Whether this gateway can actually take money right now.
     *
     * False is a working state, not an error: the guest's page says "pay at
     * the counter" and the restaurant carries on. Most of them run that way
     * for the first month.
     */
    public function isConfigured(): bool;

    /**
     * Open an order with the provider, and return what the browser needs.
     *
     * The returned array is handed to the view as-is, so anything
     * provider-specific - a key id, a script URL, a prefill block - lives in
     * the gateway and never in a Blade file.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the provider refuses
     */
    public function open(PaymentIntent $intent): array;

    /**
     * Is this claim that somebody paid actually from the provider?
     *
     * Called with whatever the browser posted back. A signature check, never
     * a status field: a browser can send any JSON it likes, and the only
     * thing it cannot forge is an HMAC of a secret it does not have.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verify(PaymentIntent $intent, array $payload): bool;

    /**
     * Read a webhook, or refuse it.
     *
     * Returns null when the signature does not check out or the event is one
     * this system does not act on. The caller treats null as "ignore, and
     * answer 200" - a webhook endpoint that returns an error to a provider
     * gets retried for a day.
     *
     * @param  array<string, string>  $headers
     * @return array{provider_payment_id: string, provider_order_id: string, paid: bool, amount: float}|null
     */
    public function parseWebhook(string $body, array $headers): ?array;

    /**
     * Send money back.
     *
     * Partial by design: `$amount` is what to return, not "all of it". A
     * guest who was charged for a dish that never arrived gets that dish
     * back, not their evening.
     *
     * Throws rather than returning false, and that is the opposite of most
     * of this interface. A refund that quietly did nothing is the worst
     * failure available here: the restaurant believes it has paid somebody
     * back, the guest is still out of pocket, and nothing anywhere says so.
     *
     * @return array{provider_refund_id: string, status: string}
     *
     * @throws \RuntimeException when the provider refuses, or cannot refund
     */
    public function refund(PaymentIntent $intent, float $amount, ?string $reason = null): array;
}
