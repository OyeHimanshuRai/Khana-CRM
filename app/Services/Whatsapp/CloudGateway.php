<?php

namespace App\Services\Whatsapp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Meta's own WhatsApp Cloud API.
 *
 * No reseller in the middle, and a free tier. The cost is that a Meta
 * Business account and an approved template are both required before
 * anything sends at all, which is why this is not the default.
 *
 * ---------------------------------------------------------------------------
 * Two things that catch everybody
 * ---------------------------------------------------------------------------
 *
 *   the phone number id  is not the phone number. It is the id Meta shows
 *                        beside it in the dashboard, and using the number
 *                        returns a 404 that reads like the API is down.
 *
 *   the language code    is part of the template's identity. "en" and
 *                        "en_US" are different templates to Meta, and the
 *                        wrong one is rejected with a message about the
 *                        template not existing.
 *
 * Both are said out loud in config/whatsapp.php as well, because the hour
 * lost to each of them is the same hour for everybody.
 */
class CloudGateway implements WhatsAppGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function key(): string
    {
        return 'cloud';
    }

    public function label(): string
    {
        return 'WhatsApp Cloud API (Meta)';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['phone_number_id'] ?? null)
            && filled($this->config['token'] ?? null);
    }

    public function send(string $to, string $template, array $variables = [], ?string $body = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken((string) $this->config['token'])
                ->asJson()
                ->acceptJson()
                ->timeout((int) ($this->config['timeout'] ?? 10))
                ->post(
                    rtrim((string) $this->config['api'], '/').'/'.$this->config['phone_number_id'].'/messages',
                    [
                        'messaging_product' => 'whatsapp',
                        'to' => $this->e164($to),
                        'type' => 'template',
                        'template' => [
                            'name' => $template,
                            'language' => ['code' => (string) ($this->config['language'] ?? 'en')],
                            'components' => $variables === [] ? [] : [[
                                'type' => 'body',
                                'parameters' => array_map(
                                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                                    array_values($variables),
                                ),
                            ]],
                        ],
                    ],
                );

            if ($response->successful()) {
                return true;
            }

            /*
             | Logged, never shown. Meta's error bodies routinely echo the
             | access token that was rejected, and the guest is told nothing
             | either way.
             */
            report(new \RuntimeException(sprintf(
                'WhatsApp refused (%d): %s',
                $response->status(),
                data_get($response->json(), 'error.message', 'no reason given'),
            )));

            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The number as Meta wants it: country code, digits, no plus.
     *
     * India is assumed for a bare ten-digit number, because that is what
     * every guest on this system types. A number that already carries a
     * country code is left alone.
     */
    private function e164(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        if (strlen($digits) === 10) {
            return '91'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '91'.substr($digits, 1);
        }

        return $digits;
    }
}
