<?php

namespace App\Services\Whatsapp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Any Indian WhatsApp reseller.
 *
 * Gupshup, AiSensy, Interakt, Wati and the rest are all "POST these
 * parameters to this URL", so they are one class and a few settings rather
 * than four classes that differ in what they call the recipient.
 *
 * Variables are sent both ways - numbered fields and a JSON array - because
 * resellers are split roughly evenly between the two and sending one extra
 * parameter is cheaper than a driver per reseller. Providers ignore what
 * they do not recognise.
 */
class HttpGateway implements WhatsAppGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function key(): string
    {
        return 'http';
    }

    public function label(): string
    {
        return 'WhatsApp provider (HTTP API)';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['url'] ?? null);
    }

    public function send(string $to, string $template, array $variables = [], ?string $body = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = array_merge($this->decode('params'), [
            (string) ($this->config['to_field'] ?? 'to') => $to,
            (string) ($this->config['template_field'] ?? 'template') => $template,
            (string) ($this->config['message_field'] ?? 'message') => $body ?? '',
            'params' => array_values($variables),
        ]);

        // The numbered form, for providers that want var1, var2, var3.
        foreach (array_values($variables) as $index => $value) {
            $payload['var'.($index + 1)] = (string) $value;
        }

        try {
            $request = Http::timeout((int) ($this->config['timeout'] ?? 10))
                ->withHeaders($this->decode('headers'));

            $method = strtoupper((string) ($this->config['method'] ?? 'POST'));

            $response = $method === 'GET'
                ? $request->get((string) $this->config['url'], $payload)
                : $request->asJson()->post((string) $this->config['url'], $payload);

            if ($response->successful()) {
                return true;
            }

            // The body is logged, never shown: a provider's error text
            // routinely contains the API key it rejected.
            report(new \RuntimeException(sprintf(
                'WhatsApp provider refused (%d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
            )));

            return false;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Read a JSON blob out of config, tolerating an empty or broken one.
     *
     * Typed by hand into an environment file, so a stray comma must mean "no
     * extra parameters" rather than a fatal error mid-service.
     *
     * @return array<string, mixed>
     */
    private function decode(string $key): array
    {
        $raw = $this->config[$key] ?? null;

        if (blank($raw)) {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            report(new \RuntimeException("WhatsApp config \"{$key}\" is not valid JSON; it was ignored."));

            return [];
        }

        return $decoded;
    }
}
