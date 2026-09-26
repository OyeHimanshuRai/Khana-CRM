<?php

namespace Khana\Kit\Events\Inbox;

use Closure;
use Khana\Kit\Events\Envelope;

/**
 * Which handler applies which type and version of event.
 *
 * Registered by each app in a service provider:
 *
 *   $handlers->on('crm.sale.recorded', 1, RecordConsumption::class);
 *
 * A type and version with no handler is stored and marked ignored, which is
 * how a receiver lets a sender emit version 2 of an event alongside version 1
 * until every receiver has moved.
 */
final class Handlers
{
    /** @var array<string, class-string<Handler>|Closure(Envelope): void> */
    private array $handlers = [];

    /**
     * @param  class-string<Handler>|Closure(Envelope): void  $handler
     */
    public function on(string $type, int $version, string|Closure $handler): void
    {
        $this->handlers[$type.'@'.$version] = $handler;
    }

    public function has(string $type, int $version): bool
    {
        return isset($this->handlers[$type.'@'.$version]);
    }

    /**
     * @return (Closure(Envelope): void)|null
     */
    public function for(string $type, int $version): ?Closure
    {
        $handler = $this->handlers[$type.'@'.$version] ?? null;

        if ($handler === null) {
            return null;
        }

        if ($handler instanceof Closure) {
            return $handler;
        }

        return fn (Envelope $event) => app($handler)->handle($event);
    }
}
