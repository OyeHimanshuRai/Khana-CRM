<?php

namespace App\Events\Outbound;

use App\Models\Shop;
use App\Support\BusinessDay as ShopDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\BusinessDay;

/**
 * Which trading day an instant belongs to, for the business_date an event
 * carries and the sales digest ERP checks it against.
 *
 * The contract says the sender works it out "from the outlet's time zone and
 * day cutoff", and both are Accounts' answers now: its copy of the outlet
 * (khana_outlets, kept by the accounts.outlet.upserted events) holds them.
 * A shop Accounts has not told CRM about yet falls back to CRM's own
 * shops.timezone and a midnight cutoff, which is what CRM's screens use.
 *
 * Events and the digest must use the same clock or ERP's nightly check would
 * find a 01:30 bill on two different days - which is why both come here.
 */
final class OutletClock
{
    /** The business date an instant falls on at this outlet: "2026-09-26". */
    public static function dateOf(Shop $shop, CarbonInterface $instant): string
    {
        [$zone, $cutoff] = self::of($shop);

        return BusinessDay::dateOf($instant, $zone, $cutoff);
    }

    /**
     * The half-open UTC range one business date covers at this outlet.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(Shop $shop, string $date): array
    {
        [$zone, $cutoff] = self::of($shop);

        return BusinessDay::window($date, $zone, $cutoff);
    }

    /**
     * @return array{0: string, 1: string}  [time zone, HH:MM cutoff]
     */
    private static function of(Shop $shop): array
    {
        $reference = $shop->ulid === null
            ? null
            : DB::table('khana_outlets')->where('id', $shop->ulid)->first(['timezone', 'day_cutoff']);

        $zone = filled($reference?->timezone) ? (string) $reference->timezone : ShopDay::zone($shop);
        $cutoff = filled($reference?->day_cutoff) ? (string) $reference->day_cutoff : BusinessDay::MIDNIGHT;

        return [$zone, $cutoff];
    }
}
