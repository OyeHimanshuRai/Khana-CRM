<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use RuntimeException;

/**
 * Which gateway is in use (§11).
 *
 * Resolved once and remembered, because a request that asked twice and got two
 * different answers - a screen rendering one provider's button and the
 * callback verifying against another's secret - is a bug nobody would find.
 *
 * Deliberately not a Laravel Manager subclass. This picks one of two or three
 * fixed classes from config; inheriting a driver framework to do that would be
 * more machinery than the thing it configures.
 */
class GatewayManager
{
    private ?PaymentGateway $resolved = null;

    public function gateway(): PaymentGateway
    {
        return $this->resolved ??= $this->make(
            (string) config('payments.gateway', 'offline')
        );
    }

    /** True when this outlet can actually take money online. */
    public function isLive(): bool
    {
        return $this->gateway()->isConfigured();
    }

    /**
     * Build one by name, falling back rather than failing.
     *
     * A typo in PAYMENT_GATEWAY should mean "no online payment tonight", not
     * a five-hundred on the guest's menu. The misconfiguration is reported so
     * somebody finds it; the restaurant keeps trading.
     */
    private function make(string $name): PaymentGateway
    {
        $config = config('payments.gateways.'.$name);

        if (! is_array($config)) {
            report(new RuntimeException("No payment gateway is configured as \"{$name}\"."));

            return new OfflineGateway();
        }

        return match ($config['driver'] ?? $name) {
            'razorpay' => new RazorpayGateway($config),
            'offline' => new OfflineGateway(),
            default => $this->unknown($name),
        };
    }

    private function unknown(string $name): PaymentGateway
    {
        report(new RuntimeException("Payment gateway \"{$name}\" has no driver."));

        return new OfflineGateway();
    }

    /** For tests and for a console command that needs a named one. */
    public function swap(PaymentGateway $gateway): void
    {
        $this->resolved = $gateway;
    }
}
