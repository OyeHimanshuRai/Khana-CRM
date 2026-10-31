<?php

namespace App\Services\Whatsapp;

use App\Contracts\WhatsAppGateway;
use RuntimeException;

/**
 * Which WhatsApp gateway is in use (§2, §15).
 *
 * Registered as a singleton, for the same reason the payment and SMS
 * managers are: one request, one gateway.
 */
class WhatsAppManager
{
    private ?WhatsAppGateway $resolved = null;

    public function gateway(): WhatsAppGateway
    {
        return $this->resolved ??= $this->make(
            (string) config('whatsapp.driver', 'log')
        );
    }

    public function isLive(): bool
    {
        return $this->gateway()->isConfigured();
    }

    /** Replace the gateway for the rest of the process. Tests only. */
    public function swap(WhatsAppGateway $gateway): void
    {
        $this->resolved = $gateway;
    }

    /**
     * Send by the key this system uses, not the provider's template name.
     *
     * The mapping lives in config('whatsapp.templates') so a restaurant whose
     * approved template is called something else changes a setting rather
     * than waiting for a release.
     *
     * @param  array<int, string>  $variables
     */
    public function send(string $to, string $template, array $variables = [], ?string $body = null): bool
    {
        $name = (string) config('whatsapp.templates.'.$template, $template);

        return $this->gateway()->send($to, $name, $variables, $body);
    }

    /**
     * Build one by name, falling back rather than failing.
     *
     * A typo in WHATSAPP_DRIVER must not be an exception on a guest's
     * journey. The log driver keeps everything working and leaves the
     * messages somewhere findable.
     */
    private function make(string $name): WhatsAppGateway
    {
        $config = (array) config('whatsapp.drivers.'.$name, []);

        return match ($name) {
            'cloud' => new CloudGateway($config),
            'http' => new HttpGateway($config),
            'log' => new LogGateway($config),
            default => $this->unknown($name),
        };
    }

    private function unknown(string $name): WhatsAppGateway
    {
        report(new RuntimeException("No WhatsApp driver is configured as \"{$name}\"; falling back to the log."));

        return new LogGateway((array) config('whatsapp.drivers.log', []));
    }
}
