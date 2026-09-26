<?php

namespace Khana\Kit\Events\Delivery;

/**
 * What one delivery run did for one receiver.
 */
final class DeliveryReport
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const WAITING = 'waiting';

    public const BUSY = 'busy';

    private function __construct(
        public readonly string $outcome,
        public readonly int $sent = 0,
        public readonly int $lastSeq = 0,
        public readonly ?string $error = null,
    ) {}

    public static function sent(int $count, int $lastSeq): self
    {
        return new self(self::SENT, $count, $lastSeq);
    }

    public static function failed(int $sentBefore, int $lastSeq, string $error): self
    {
        return new self(self::FAILED, $sentBefore, $lastSeq, $error);
    }

    public static function waiting(int $lastSeq): self
    {
        return new self(self::WAITING, 0, $lastSeq);
    }

    public static function busy(): self
    {
        return new self(self::BUSY);
    }
}
