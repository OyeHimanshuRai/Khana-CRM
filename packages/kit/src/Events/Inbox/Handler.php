<?php

namespace Khana\Kit\Events\Inbox;

use Khana\Kit\Events\Envelope;

/**
 * Applies one type and version of event. Runs inside a transaction that
 * also marks the event applied, so its effect lands exactly once.
 *
 * Throw RetryLater when the event cannot be applied *yet* - it names an
 * outlet this service has not heard about - and anything else when it is
 * wrong.
 */
interface Handler
{
    public function handle(Envelope $event): void;
}
