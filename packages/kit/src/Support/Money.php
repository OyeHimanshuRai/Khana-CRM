<?php

namespace Khana\Kit\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Rupees, held as whole paise.
 *
 * Every contract carries money as an integer number of paise (52500 is
 * ₹525.00). A float cannot hold 0.1 exactly, and two services that each
 * rounded their own float would sooner or later disagree about a bill by a
 * paisa - which is a disagreement with the GST return.
 *
 * Rounding is half away from zero, the same rule round() and every invoice
 * written so far have used.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(public readonly int $paise) {}

    public static function paise(int $paise): self
    {
        return new self($paise);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** From a rupee amount as typed: "525", "525.5", "525.50". Floats are refused on purpose. */
    public static function rupees(string|int $amount): self
    {
        return is_int($amount)
            ? new self(Decimal::mulDiv($amount, 100, 1))
            : new self(Decimal::parse($amount, 2));
    }

    public function plus(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    public function minus(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    public function negate(): self
    {
        return new self(-$this->paise);
    }

    /** A unit price times a quantity, to the nearest paisa. */
    public function times(Quantity|string|int $quantity): self
    {
        return new self(Decimal::mulDiv($this->paise, Quantity::of($quantity)->units, Quantity::ONE));
    }

    /** A percentage of this amount - "5", "2.5", "18" - to the nearest paisa. */
    public function percent(string|int $rate): self
    {
        $units = is_int($rate) ? $rate * 10_000 : Decimal::parse($rate, 4);

        return new self(Decimal::mulDiv($this->paise, $units, 1_000_000));
    }

    /**
     * Split this amount in proportion to the weights, exactly.
     *
     * The paise that do not divide evenly go to the shares with the largest
     * remainders, so the parts always add back up to the whole. This is how
     * an invoice-level discount is spread across its lines: subtracting it
     * from the total instead would overstate the tax.
     *
     * @param  array<array-key, int|self>  $weights
     * @return array<array-key, self>  keyed like $weights
     */
    public function allocate(array $weights): array
    {
        $values = array_map(fn ($w) => $w instanceof self ? $w->paise : $w, $weights);

        foreach ($values as $value) {
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Allocation weights must be whole, non-negative numbers.');
            }
        }

        $total = array_sum($values);

        if ($total === 0) {
            throw new InvalidArgumentException('Cannot allocate against weights that add up to nothing.');
        }

        $amount = abs($this->paise);
        $shares = [];
        $remainders = [];

        foreach ($values as $key => $weight) {
            if ($weight !== 0 && $amount > intdiv(PHP_INT_MAX, $weight)) {
                throw new InvalidArgumentException('Amount is too large to allocate exactly.');
            }

            $shares[$key] = intdiv($amount * $weight, $total);
            $remainders[$key] = ($amount * $weight) % $total;
        }

        $left = $amount - array_sum($shares);

        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left === 0) {
                break;
            }

            $shares[$key]++;
            $left--;
        }

        $sign = $this->paise < 0 ? -1 : 1;

        return array_map(fn (int $share) => new self($sign * $share), $shares);
    }

    /**
     * To the nearest rupee, with the difference kept as round-off so the
     * printed arithmetic still adds up.
     *
     * @return array{0: self, 1: self}  [rounded total, round-off]
     */
    public function roundToRupee(): array
    {
        $rounded = new self(Decimal::mulDiv($this->paise, 1, 100) * 100);

        return [$rounded, $rounded->minus($this)];
    }

    public function isZero(): bool
    {
        return $this->paise === 0;
    }

    public function isNegative(): bool
    {
        return $this->paise < 0;
    }

    public function isPositive(): bool
    {
        return $this->paise > 0;
    }

    public function equals(self $other): bool
    {
        return $this->paise === $other->paise;
    }

    public function compare(self $other): int
    {
        return $this->paise <=> $other->paise;
    }

    /** "525.00" - for a form field or a CSV column. */
    public function toRupees(): string
    {
        return Decimal::format($this->paise, 2);
    }

    /** "₹1,25,000.00" - Indian digit grouping, for a screen or a slip. */
    public function format(): string
    {
        $rupees = $this->toRupees();
        $negative = str_starts_with($rupees, '-');
        [$whole, $fraction] = explode('.', ltrim($rupees, '-'));

        if (strlen($whole) > 3) {
            // Thousands, then pairs: 1,23,45,678.
            $whole = preg_replace('/\B(?=(\d{2})+$)/', ',', substr($whole, 0, -3)).','.substr($whole, -3);
        }

        return ($negative ? '-' : '').'₹'.$whole.'.'.$fraction;
    }

    public function __toString(): string
    {
        return $this->toRupees();
    }

    /** Whole paise: the contract form. */
    public function jsonSerialize(): int
    {
        return $this->paise;
    }
}
