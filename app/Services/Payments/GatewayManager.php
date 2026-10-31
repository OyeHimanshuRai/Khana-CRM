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

    private ?RazorpayGateway $platform = null;

    /**
     * The platform's own gateway, for restaurants paying for a plan.
     *
     * Never the restaurant's gateway above: that account receives a
     * restaurant's guests' money, and a subscription fee must not land there.
     */
    public function platform(): RazorpayGateway
    {
        return $this->platform ??= new RazorpayGateway((array) config('payments.platform'));
    }

    /** For tests that need a gateway with known credentials. */
    public function swapPlatform(RazorpayGateway $gateway): void
    {
        $this->platform = $gateway;
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
