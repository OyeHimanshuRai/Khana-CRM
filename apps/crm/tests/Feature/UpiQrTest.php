<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Support\UpiQr;
use Tests\TestCase;

/**
 * The code a guest scans to pay part of a bill (SRS 8).
 *
 * Every property here is about the URI rather than the picture, because the
 * picture is BaconQrCode's problem and the URI is ours - and the ways it goes
 * wrong all end with a guest's money somewhere it should not be:
 *
 *   1. The amount encoded is the tender's, not the bill's. A table paying
 *      ₹200 in cash and ₹60 by phone must be shown a code for sixty.
 *
 *   2. Two decimals, always. Apps disagree about whether a bare "60" means
 *      sixty rupees or sixty paise, and the ones that guess are the ones a
 *      guest is holding.
 *
 *   3. A payee name with an ampersand in it does not truncate the URI. "Raj &
 *      Sons" concatenated in raw ends the query string at the name, and the
 *      phone gets a code with no amount in it.
 *
 *   4. A branch with no VPA gets nothing rather than an empty code. A QR that
 *      opens a banking app with no payee is worse than no QR.
 */
class UpiQrTest extends TestCase
{
    private function shop(array $attributes = []): Shop
    {
        return new Shop(array_merge([
            'name' => 'Jaipur Rooftop',
            'upi_id' => 'rooftop@okhdfcbank',
            'upi_name' => 'Jaipur Rooftop Restaurant',
        ], $attributes));
    }

    /** @return array<string, string> the URI's query, decoded */
    private function query(string $uri): array
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $params);

        return $params;
    }

    public function test_it_encodes_the_tender_not_the_bill(): void
    {
        // The table owes 260; sixty of it is going on UPI.
        $uri = UpiQr::uri($this->shop(), 60.0, 'Table T4');

        $params = $this->query($uri);

        $this->assertSame('60.00', $params['am']);
        $this->assertSame('rooftop@okhdfcbank', $params['pa']);
        $this->assertSame('INR', $params['cu']);
        $this->assertSame('Table T4', $params['tn']);
    }

    public function test_the_amount_always_carries_two_decimals(): void
    {
        $this->assertSame('60.00', $this->query(UpiQr::uri($this->shop(), 60))['am']);
        $this->assertSame('0.50', $this->query(UpiQr::uri($this->shop(), 0.5))['am']);
        $this->assertSame('1234.50', $this->query(UpiQr::uri($this->shop(), 1234.5))['am']);
    }

    public function test_an_ampersand_in_the_payee_does_not_truncate_the_uri(): void
    {
        $uri = UpiQr::uri($this->shop(['upi_name' => 'Raj & Sons']), 60.0);

        // The literal & must not appear outside the separators, or everything
        // after the name is a parameter of its own and `am` is gone.
        $this->assertStringContainsString('%26', $uri);

        $params = $this->query($uri);

        $this->assertSame('Raj & Sons', $params['pn']);
        $this->assertSame('60.00', $params['am'], 'the amount survived the name');
    }

    public function test_the_payee_falls_back_to_the_branch_name(): void
    {
        $params = $this->query(UpiQr::uri($this->shop(['upi_name' => null]), 60.0));

        $this->assertSame('Jaipur Rooftop', $params['pn']);
    }

    public function test_a_branch_with_no_vpa_gets_no_code(): void
    {
        $without = $this->shop(['upi_id' => null]);

        $this->assertFalse(UpiQr::availableFor($without));
        $this->assertNull(UpiQr::uri($without, 60.0));
        $this->assertNull(UpiQr::render($without, 60.0));
        $this->assertFalse(UpiQr::availableFor(null));
    }

    public function test_render_returns_an_inline_svg_with_no_xml_prolog(): void
    {
        $code = UpiQr::render($this->shop(), 60.0, 'Table T4');

        $this->assertNotNull($code);
        $this->assertSame('60.00', $code['amount']);
        $this->assertSame('Jaipur Rooftop Restaurant', $code['payee']);
        $this->assertStringStartsWith('<svg', $code['svg']);
        // A second XML prolog mid-document renders as text above the code,
        // which reads as a broken sticker rather than a broken page. (Not
        // spelled out here: a literal close-tag ends PHP inside a comment
        // too, which is how this file first failed to parse.)
        $this->assertStringNotContainsString('<?xml', $code['svg']);
    }

    public function test_a_long_note_is_trimmed_rather_than_sent_whole(): void
    {
        $uri = UpiQr::uri($this->shop(), 60.0, str_repeat('x', 200));

        $this->assertSame(50, mb_strlen($this->query($uri)['tn']));
    }
}
