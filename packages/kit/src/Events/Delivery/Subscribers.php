<?php

namespace Khana\Kit\Events\Delivery;

/**
 * The receivers named in config('khana.events.subscribers').
 *
 * Read on every call rather than once, so a test or a console command that
 * changes the config sees the change.
 */
final class Subscribers
{
    /**
     * Receivers with a url and a secret. One left half-configured - no url
     * yet on a fresh server - is skipped rather than failing every send.
     *
     * @return array<string, Subscriber>
     */
    public function all(): array
    {
        $subscribers = [];

        foreach ((array) config('khana.events.subscribers', []) as $name => $settings) {
            if (! is_array($settings) || blank($settings['url'] ?? null) || blank($settings['secret'] ?? null)) {
                continue;
            }

            $subscribers[$name] = Subscriber::fromConfig((string) $name, $settings);
        }

        return $subscribers;
    }

    public function find(string $name): ?Subscriber
    {
        return $this->all()[$name] ?? null;
    }
}
