<?php

namespace Khana\Kit\Events\Inbox;

use RuntimeException;

final class ReceiveRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
