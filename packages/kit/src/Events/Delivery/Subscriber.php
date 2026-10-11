<?php

namespace Khana\Kit\Events\Delivery;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Khana\Kit\Events\OutboxEvent;

/**
 * One service this one sends events to.
 */
final class Subscriber
{
    /**
     * @param  list<string>  $types  patterns such as "crm.*"
     */
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly string $secret,
        public readonly array $types,
        public readonly ?DeliveryFilter $filter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $url = $config['url'] ?? null;
        $secret = $config['secret'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            throw new InvalidArgumentException("Event subscriber \"{$name}\" needs a url and a secret.");
        }

        $filter = $config['filter'] ?? null;

        return new self(
            name: $name,
            url: $url,
            secret: $secret,
            types: array_values((array) ($config['types'] ?? ['*'])),
            filter: $filter === null ? null : app($filter),
        );
    }

    public function wants(OutboxEvent $event): bool
    {
        return Str::is($this->types, $event->type)
            && ($this->filter === null || $this->filter->accepts($this->name, $event));
    }
}
