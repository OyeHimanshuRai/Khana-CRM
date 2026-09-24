<?php

namespace App\Support;

use App\Models\Shop;

/**
 * The code a guest scans to pay a bill (SRS 8).
 *
 * ---------------------------------------------------------------------------
 * What this is, and what it is not
 * ---------------------------------------------------------------------------
 *
 * A UPI intent URI, rendered as a QR by App\Support\QrCode. A phone reading
 * it opens its banking app with the payee and the amount already filled in;
 * the guest confirms, and the money moves between their bank and the shop's.
 *
 * Nothing here touches that transfer. The platform is not in the payment
 * path, receives no callback and is told nothing when the guest pays - which
 * is precisely why the bill screen still asks the cashier to confirm the
 * tender before raising the invoice. A code that printed itself and settled
 * the table would settle it for payments that never arrived.
 *
 * ---------------------------------------------------------------------------
 * The amount is in the code on purpose
 * ---------------------------------------------------------------------------
 *
 * A code with no amount makes the guest type one, and at a till the number
 * they type is the number that gets paid - so a mistyped 60 becomes a 6 that
 * nobody notices until the day's cash is counted. Putting it in the URI is
 * also what makes a split work: ₹200 in cash and ₹60 scanned needs a code for
 * sixty rupees, not for the bill.
 */
final class UpiQr
{
    /** UPI caps the note at a modest length and drops longer ones silently. */
    private const NOTE_LIMIT = 50;

    /**
     * Whether this branch can be paid by scanning at all.
     *
     * The bill screen asks before it offers anything, because a branch with
     * no VPA has nothing to encode and a code built from an empty string is
     * a QR that opens a banking app with no payee in it.
     */
    public static function availableFor(?Shop $shop): bool
    {
        return filled($shop?->upi_id);
    }

    /**
     * The `upi://pay` URI for one tender.
     *
     * Returns null rather than a broken code when the branch has no VPA, so
     * every caller has to deal with the absent case and none of them can
     * accidentally render an empty one.
     *
     * @param  float  $amount  what this tender is for, not what the bill is
     * @param  string|null  $note  shown in the guest's app; a bill or table reference
     */
    public static function uri(?Shop $shop, float $amount, ?string $note = null): ?string
    {
        if (! self::availableFor($shop)) {
            return null;
        }

        /*
         | Built with http_build_query rather than by concatenation, because
         | a payee name is free text off a settings form and an unescaped
         | ampersand in "Raj & Sons" would truncate the URI at the name and
         | hand the phone a code with no amount in it.
         */
        $params = [
            'pa' => $shop->upi_id,
            'pn' => $shop->upi_name ?: $shop->name,
            // Two decimals always. Some apps read "60" as sixty rupees and
            // others as sixty paise, and the ones that guess are the ones a
            // guest is holding.
            'am' => number_format(max(0, $amount), 2, '.', ''),
            'cu' => 'INR',
        ];

        if (filled($note)) {
            $params['tn'] = mb_substr($note, 0, self::NOTE_LIMIT);
        }

        return 'upi://pay?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The same thing as inline SVG, ready to drop into the bill screen.
     *
     * @return array{uri: string, svg: string, payee: string, amount: string}|null
     */
    public static function render(?Shop $shop, float $amount, ?string $note = null, int $size = 220): ?array
    {
        $uri = self::uri($shop, $amount, $note);

        if ($uri === null) {
            return null;
        }

        return [
            'uri' => $uri,
            'svg' => QrCode::inline($uri, $size),
            'payee' => $shop->upi_name ?: $shop->name,
            'amount' => number_format(max(0, $amount), 2, '.', ''),
        ];
    }
}
