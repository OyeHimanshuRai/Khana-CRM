<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes, as inline SVG.
 *
 * SVG rather than PNG for one practical reason: a table sticker gets printed,
 * and a raster code sized for a screen turns into soft edges at 25mm that
 * cheap phone cameras give up on. SVG also needs no imagick, which this
 * install does not have.
 *
 * The markup is returned rather than written to disk. A QR is a pure function
 * of its token and costs about a millisecond; caching one would mean a file
 * per table to invalidate every time a code is regenerated, which is more
 * ways to be wrong than it saves.
 */
final class QrCode
{
    /**
     * Error correction is left at the library's default (level L).
     *
     * Higher levels buy tolerance for a damaged or greasy sticker at the cost
     * of a denser grid - and density is the thing that actually breaks
     * scanning at sticker size. A restaurant that finds codes failing should
     * reprint them larger before making them denser.
     */
    private const MARGIN_MODULES = 1;

    /**
     * @param  int  $size  rendered edge in pixels; the SVG scales anyway, but
     *                     the viewBox has to say something
     */
    public static function svg(string $content, int $size = 240): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, self::MARGIN_MODULES),
            new SvgImageBackEnd(),
        ));

        return $writer->writeString($content);
    }

    /**
     * The same code with its XML prolog stripped, for inlining into a page.
     *
     * A second `<?xml ... ?>` in the middle of an HTML document is not an
     * error any browser reports - it just renders as text above the code,
     * which looks like a bug in the sticker rather than in the page.
     */
    public static function inline(string $content, int $size = 240): string
    {
        $svg = self::svg($content, $size);

        return preg_replace('/^<\?xml.*?\?>\s*/s', '', $svg) ?? $svg;
    }

    /** A data: URI, for an <img src> or a CSS background. */
    public static function dataUri(string $content, int $size = 240): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($content, $size));
    }
}
