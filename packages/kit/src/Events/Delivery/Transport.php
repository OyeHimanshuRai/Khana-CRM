<?php

namespace Khana\Kit\Events\Delivery;

interface Transport
{
    /**
     * Hand one signed batch to a receiver. Returns only if it answered 2xx.
     *
     * @throws DeliveryFailed
     */
    public function send(Subscriber $to, string $source, string $body): void;
}
