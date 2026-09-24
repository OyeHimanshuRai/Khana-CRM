<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Log;

/**
 * Write the message to the log instead of sending it.
 *
 * The default, and not a placeholder. It means the OTP step can be switched
 * on, exercised end to end and demonstrated on a laptop with no provider
 * account anywhere - and the person reading the code out of the log is
 * exercising exactly the same path as the guest reading it off a phone.
 *
 * A feature that cannot be tried without a paid account does not get tried,
 * and therefore ships broken.
 */
class LogGateway implements SmsGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function key(): string
    {
        return 'log';
    }

    public function label(): string
    {
        return 'Write to the log (no messages are sent)';
    }

    /**
     * Always true: writing to a log cannot be misconfigured.
     *
     * This is what lets OTP be switched on before a provider is chosen. It
     * is also why the settings screen says, in words, that nothing is being
     * sent - a driver that quietly succeeds is only safe while everybody
     * knows it is not sending anything.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $message): bool
    {
        Log::channel((string) ($this->config['channel'] ?? 'stack'))
            ->info('SMS ['.$to.'] '.$message);

        return true;
    }
}
