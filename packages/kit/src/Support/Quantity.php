<?php

namespace Khana\Kit\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A quantity with up to four decimal places, held as whole ten-thousandths.
 *
 * Contracts carry quantities as decimal strings ("0.2500") rather than
 * floats: 0.1 + 0.2 kg of flour has to come out as 0.3 on both sides of a
 * service boundary, and a float cannot promise that.
 */
final class Quantity implements JsonSerializable, Stringable
{
    public const SCALE = 4;

    public const ONE = 10_000;

    private function __construct(public readonly int $units) {}

    /** From a decimal string or a whole number. Floats are refused on purpose. */
    public static function of(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return new self(self::checkedMultiply($value, self::ONE));
        }

        return new self(Decimal::parse($value, self::SCALE));
    }

    public static function units(int $units): self
    {
        return new self($units);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self|string|int $other): self
    {
        return new self($this->units + self::of($other)->units);
    }

    public function minus(self|string|int $other): self
    {
        return new self($this->units - self::of($other)->units);
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    public function isPositive(): bool
    {
        return $this->units > 0;
    }

    public function equals(self|string|int $other): bool
    {
        return $this->units === self::of($other)->units;
    }

    public function compare(self|string|int $other): int
    {
        return $this->units <=> self::of($other)->units;
    }

    /** Always four places: "2.0000". What a contract carries. */
    public function toString(): string
    {
        return Decimal::format($this->units, self::SCALE);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    private static function checkedMultiply(int $a, int $b): int
    {
        $result = $a * $b;

        if (! is_int($result)) {
            throw new InvalidArgumentException('Quantity is too large.');
        }

        return $result;
    }
}
