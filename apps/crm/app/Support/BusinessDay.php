<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Carbon;

/**
 * What "today" means to the outlet, rather than to the server.
 *
 * ------------------------------------------------------------------------
 * A column that was collected and never read
 * ------------------------------------------------------------------------
 *
 * `shops.timezone` is on the create form, it is validated against the real
 * zone list, and the shop's detail page prints it back. Nothing has ever read
 * it. `config('app.timezone')` is UTC, so every date boundary in the product -
 * the dashboard's "today", the unclosed-register alert, the report presets,
 * the day close - was drawn at midnight UTC.
 *
 * Every shop on this install trades in Asia/Kolkata, which is five and a half
 * hours ahead. Restaurants are precisely the business that trades past
 * midnight: everything sold between 00:00 and 05:29 local was counted into the
 * previous day, reconciled against the previous day's float, and shown to a
 * manager at 1am under the heading "Today". The unclosed-day alert fired five
 * and a half hours late, every day.
 *
 * ------------------------------------------------------------------------
 * Storage stays UTC
 * ------------------------------------------------------------------------
 *
 * The fix is not to move the application's clock. Rows already written are
 * naive UTC, and changing `app.timezone` would silently reinterpret every one
 * of them - the same string meaning a different instant. Instants keep being
 * stored in UTC. What changes is where a *day* starts, which is a question
 * about the outlet and has to be asked of the outlet.
 *
 * Two answers, because the columns are two different kinds:
 *
 *   today()   a calendar date, for the columns that are already a business
 *             day - `cash_registers.business_date`, `expenses.spent_on` - and
 *             for anything humans key on, like an alert's dedupe key.
 *
 *   window()  a half-open UTC range, for the columns that are an instant -
 *             `invoices.invoiced_at`, `paid_at`, any `created_at`. Half-open
 *             rather than BETWEEN so the last second of the day cannot be
 *             counted twice by two adjacent ranges.
 *
 * `whereDate()` cannot be made zone-aware - it asks the database to truncate,
 * and the database does not know whose day it is. Anything comparing an
 * instant to a day has to use window().
 */
final class BusinessDay
{
    /**
     * The zone the outlet trades in, falling back to the application's.
     *
     * Nullable shop rather than required: most callers are inside a request
     * that already has one selected, and the consolidated view - a Super
     * Admin across every branch - genuinely has none.
     */
    public static function zone(?Shop $shop = null): string
    {
        $shop ??= CurrentShop::get();

        $zone = trim((string) ($shop?->timezone ?? ''));

        return $zone !== '' ? $zone : (string) config('app.timezone');
    }

    /** Midnight at the start of the outlet's current day, in its own zone. */
    public static function today(?Shop $shop = null): Carbon
    {
        return Carbon::now(self::zone($shop))->startOfDay();
    }

    /**
     * The UTC range covering one of the outlet's days.
     *
     * Use it as `->where($col, '>=', $from)->where($col, '<', $to)`. The upper
     * bound is exclusive, which is why it is not a `whereBetween`.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(Carbon|string|null $day = null, ?Shop $shop = null): array
    {
        $zone = self::zone($shop);

        $start = $day instanceof Carbon
            ? $day->copy()->setTimezone($zone)->startOfDay()
            : Carbon::parse($day ?? 'today', $zone)->startOfDay();

        return [
            $start->copy()->setTimezone('UTC'),
            $start->copy()->addDay()->setTimezone('UTC'),
        ];
    }

    /**
     * The UTC range covering a span of the outlet's days, both ends included
     * as whole days - what a business means by "1st to 31st".
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function range(Carbon|string $from, Carbon|string $to, ?Shop $shop = null): array
    {
        [$start] = self::window($from, $shop);
        [, $end] = self::window($to, $shop);

        return [$start, $end];
    }
}
