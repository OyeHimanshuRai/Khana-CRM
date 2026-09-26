<?php

namespace Khana\Kit\Events\Delivery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Khana\Kit\Events\Signature;

/**
 * POSTs a batch over HTTPS, signed with the secret this pair of services
 * shares. The exact bytes signed are the bytes sent.
 */
final class HttpTransport implements Transport
{
    public function send(Subscriber $to, string $source, string $body): void
    {
        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                Signature::SOURCE_HEADER => $source,
                Signature::HEADER => Signature::sign($body, $to->secret),
            ])
                ->withBody($body, 'application/json')
                ->connectTimeout(5)
                ->timeout((int) config('khana.events.timeout', 10))
                ->post($to->url);
        } catch (ConnectionException $e) {
            throw new DeliveryFailed("{$to->name} could not be reached: ".$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new DeliveryFailed(sprintf(
                '%s answered %d: %s',
                $to->name,
                $response->status(),
                Str::limit(trim($response->body()), 300),
            ));
        }
    }
}
