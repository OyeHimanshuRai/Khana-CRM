<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use RuntimeException;

/**
 * Which SMS gateway is in use (§3.8).
 *
 * Registered as a singleton in AppServiceProvider, for the same reason the
 * payment manager is: a request that asked twice and got two different
 * answers - one screen offering to send a code, another refusing to check it
 * - is a bug nobody would find.
 *
 * `swap()` exists for tests, which must never send anything anywhere.
 */
class SmsManager
{
    private ?SmsGateway $resolved = null;

    public function gateway(): SmsGateway
    {
        return $this->resolved ??= $this->make(
            (string) config('sms.driver', 'log')
        );
    }

    /** True when something could actually be sent right now. */
    public function isLive(): bool
    {
        return $this->gateway()->isConfigured();
    }

    /** Replace the gateway for the rest of the process. Tests only. */
    public function swap(SmsGateway $gateway): void
    {
        $this->resolved = $gateway;
    }

    /**
     * Build one by name, falling back rather than failing.
     *
     * A typo in SMS_DRIVER must not be a five-hundred on a guest's phone.
     * Falling back to the log driver keeps the journey working and leaves
     * the codes somewhere a person can find them, which is the least
     * surprising failure available.
     */
    private function make(string $name): SmsGateway
    {
        $config = (array) config('sms.drivers.'.$name, []);

        return match ($name) {
            'http' => new HttpGateway($config),
            'log' => new LogGateway($config),
            default => $this->unknown($name),
        };
    }

    private function unknown(string $name): SmsGateway
    {
        report(new RuntimeException("No SMS driver is configured as \"{$name}\"; falling back to the log."));

        return new LogGateway((array) config('sms.drivers.log', []));
    }
}
