<?php

namespace App\Events\Outbound;

use App\Models\Order;
use InvalidArgumentException;
use Khana\Kit\Support\Quantity;
use Khana\Kit\Support\Ulid;

/**
 * CRM's own numbers, written the way contracts/events/defs.json reads them.
 *
 * CRM keeps money as decimal rupees (15,2 and 15,4 columns) and quantities to
 * three places; the contracts carry whole paise and four-place decimal
 * strings. The conversion lives here, once, so a sale, its API twin and a
 * kitchen line can never round the same figure two ways.
 *
 * Nothing here goes through a float that it can avoid. The decimal casts
 * hand back strings ("250.0000"), and those are read digit by digit.
 */
final class Wire
{
    /**
     * Kitchen stages as the contract names them (defs.json kitchenStage).
     *
     * CRM's ladder kept the web flow's words for its first two rungs - see
     * Order::KITCHEN_FLOW - so "pending" is the contract's "placed" and
     * "confirmed" its "accepted".
     */
    public const STAGES = [
        Order::PENDING => 'placed',
        Order::CONFIRMED => 'accepted',
        Order::PREPARING => 'preparing',
        Order::READY => 'ready',
        Order::SERVED => 'served',
    ];

    /**
     * Rupees to whole paise, rounded half away from zero - the rule
     * InvoiceService and the kit's Money both use.
     */
    public static function paise(string|int|float|null $rupees): int
    {
        if ($rupees === null || $rupees === '') {
            return 0;
        }

        if (is_int($rupees)) {
            return $rupees * 100;
        }

        // Ten-thousandths of a rupee, exactly; then to the nearest paisa.
        $units = Quantity::of(self::fourPlaces($rupees))->units;
        $paise = intdiv(abs($units) + 50, 100);

        return $units < 0 ? -$paise : $paise;
    }

    /** Never below zero: for the contract fields that are nonNegativePaise. */
    public static function nonNegativePaise(string|int|float|null $rupees): int
    {
        return max(0, self::paise($rupees));
    }

    /** "2.0000" - the contract's quantity, always four places. */
    public static function quantity(string|int|float $value): string
    {
        return Quantity::of(is_int($value) ? $value : self::fourPlaces($value))->toString();
    }

    public static function stage(?string $kitchenStatus): ?string
    {
        return self::STAGES[$kitchenStatus] ?? null;
    }

    /** A name the contract accepts: 1 to 200 characters, or null when there is none. */
    public static function name(?string $value, int $max = 200): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Free text cut to the contract's limit, null when blank. */
    public static function text(?string $value, int $max): ?string
    {
        return self::name($value, $max);
    }

    /** A ulid the contract will take, or null - never a malformed id. */
    public static function ulid(?string $value): ?string
    {
        return Ulid::isValid($value) ? $value : null;
    }

    /**
     * @param  iterable<string|null>  $values
     * @return list<string>  valid, unique, in first-seen order
     */
    public static function ulids(iterable $values): array
    {
        $out = [];

        foreach ($values as $value) {
            if (Ulid::isValid($value) && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * A decimal string with at most four places.
     *
     * A value with more (a float that went through arithmetic, say) is
     * rounded to four here rather than refused - CRM never stores more than
     * four, so this only ever trims noise.
     */
    private static function fourPlaces(string|float $value): string
    {
        if (is_float($value)) {
            return number_format($value, 4, '.', '');
        }

        $value = trim($value);

        if (preg_match('/^-?\d+(?:\.(\d+))?$/', $value, $m) !== 1) {
            throw new InvalidArgumentException("\"{$value}\" is not a decimal number.");
        }

        return strlen($m[1] ?? '') > 4 ? number_format((float) $value, 4, '.', '') : $value;
    }
}
