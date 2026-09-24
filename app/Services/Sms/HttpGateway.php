<?php

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Any provider whose API is "send these parameters to this URL".
 *
 * Which is all of them, in this market. MSG91, Fast2SMS, Textlocal, Kaleyra
 * and Gupshup differ in what they call the recipient field and where they
 * want the API key, and in nothing else that matters here - so those two
 * things are configuration and there is one class rather than five.
 *
 * The alternative was to guess which provider the restaurant already pays
 * for and hard-code it. That guess is wrong most of the time, and being
 * wrong means a release rather than a setting.
 *
 * ---------------------------------------------------------------------------
 * Failure is ordinary
 * ---------------------------------------------------------------------------
 *
 * No balance, an opted-out number, a carrier dropping traffic, a provider
 * having an afternoon - all normal, and none of them may become an exception
 * on a guest's phone. Everything here ends in a bool, and anything worth
 * somebody's attention goes through report().
 */
class HttpGateway implements SmsGateway
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    public function key(): string
    {
        return 'http';
    }

    public function label(): string
    {
        return 'SMS provider (HTTP API)';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['url'] ?? null);
    }

    public function send(string $to, string $message): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $payload = array_merge($this->decode('params'), [
            (string) ($this->config['to_field'] ?? 'to') => $to,
            (string) ($this->config['message_field'] ?? 'message') => $message,
        ]);

        try {
            $request = Http::timeout((int) ($this->config['timeout'] ?? 8))
                ->withHeaders($this->decode('headers'));

            $method = strtoupper((string) ($this->config['method'] ?? 'POST'));

            $response = $method === 'GET'
                ? $request->get((string) $this->config['url'], $payload)
                : $request->asForm()->post((string) $this->config['url'], $payload);

            if ($response->successful()) {
                return true;
            }

            /*
             | The body is logged but not shown to anybody. A provider's
             | error text routinely contains the API key it rejected, and
             | the guest is told "we could not send that" either way.
             */
            report(new \RuntimeException(sprintf(
                'SMS provider refused (%d): %s',
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
     * These come from the environment, where they are typed by hand. A stray
     * comma must mean "no extra parameters", not a fatal error in the middle
     * of a guest's checkout.
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
            report(new \RuntimeException("SMS config \"{$key}\" is not valid JSON; it was ignored."));

            return [];
        }

        return $decoded;
    }
}
