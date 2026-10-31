<?php

namespace App\Support;

/**
 * Bytes a thermal printer understands (SRS 6, 8, 21).
 *
 * ---------------------------------------------------------------------------
 * Why this is a handful of constants rather than a library
 * ---------------------------------------------------------------------------
 *
 * ESC/POS is enormous, and about fifteen bytes of it are needed to print a
 * kitchen ticket: initialise, align, embolden, double-height, feed, cut. Every
 * thermal printer sold into a restaurant in this market implements those
 * fifteen the same way; the parts where printers differ are barcodes,
 * graphics, drawers and code pages, none of which a KOT uses.
 *
 * ---------------------------------------------------------------------------
 * Fixed columns, and it matters
 * ---------------------------------------------------------------------------
 *
 * A thermal printer has no proportional font and no word wrap worth the name.
 * A line longer than the paper is silently truncated or spills in a way that
 * makes two dishes look like one - so every line this builder emits is padded
 * or wrapped to a known width, and the width comes from the printer row.
 *
 * 58mm paper is 32 columns, 80mm is 42 or 48 depending on the font. Those are
 * the numbers to put on a printer, and they are stored rather than derived
 * because the conversion depends on a font setting nobody wants to model.
 */
class EscPos
{
    public const INIT = "\x1B\x40";

    public const ALIGN_LEFT = "\x1B\x61\x00";

    public const ALIGN_CENTRE = "\x1B\x61\x01";

    public const ALIGN_RIGHT = "\x1B\x61\x02";

    public const BOLD_ON = "\x1B\x45\x01";

    public const BOLD_OFF = "\x1B\x45\x00";

    /** Double height and width - what a station name is printed in. */
    public const BIG_ON = "\x1D\x21\x11";

    public const BIG_OFF = "\x1D\x21\x00";

    /** Partial cut, after feeding three lines clear of the blade. */
    public const CUT = "\x1D\x56\x41\x03";

    private string $out = '';

    public function __construct(private readonly int $columns = 42)
    {
        $this->out .= self::INIT;
    }

    public function raw(string $bytes): self
    {
        $this->out .= $bytes;

        return $this;
    }

    /** A line of text, wrapped to the paper rather than truncated. */
    public function line(string $text = ''): self
    {
        if ($text === '') {
            $this->out .= "\n";

            return $this;
        }

        foreach (explode("\n", wordwrap($this->clean($text), $this->columns, "\n", true)) as $part) {
            $this->out .= $part."\n";
        }

        return $this;
    }

    public function centre(string $text): self
    {
        return $this->raw(self::ALIGN_CENTRE)->line($text)->raw(self::ALIGN_LEFT);
    }

    public function bold(string $text): self
    {
        return $this->raw(self::BOLD_ON)->line($text)->raw(self::BOLD_OFF);
    }

    public function big(string $text): self
    {
        return $this->raw(self::ALIGN_CENTRE.self::BIG_ON)
            ->line($text)
            ->raw(self::BIG_OFF.self::ALIGN_LEFT);
    }

    /**
     * Left text and right text on one line, with the gap filled.
     *
     * The workhorse: "2x Paneer Tikka" against "₹520", a table number against
     * a time. Truncates the left rather than the right, because the price and
     * the quantity are the parts nobody may lose.
     */
    public function columns(string $left, string $right): self
    {
        $right = $this->clean($right);
        $left = $this->clean($left);

        $room = max(1, $this->columns - mb_strlen($right) - 1);

        if (mb_strlen($left) > $room) {
            $left = mb_substr($left, 0, $room);
        }

        $gap = max(1, $this->columns - mb_strlen($left) - mb_strlen($right));

        $this->out .= $left.str_repeat(' ', $gap).$right."\n";

        return $this;
    }

    public function rule(string $char = '-'): self
    {
        $this->out .= str_repeat($char, $this->columns)."\n";

        return $this;
    }

    public function feed(int $lines = 1): self
    {
        $this->out .= str_repeat("\n", max(0, $lines));

        return $this;
    }

    public function cut(bool $enabled = true): self
    {
        if ($enabled) {
            $this->out .= self::CUT;
        }

        return $this;
    }

    public function bytes(): string
    {
        return $this->out;
    }

    /**
     * Strip what a thermal printer cannot render.
     *
     * Two problems, both of which show up as mojibake on a ticket in front of
     * a cook:
     *
     *   - control characters in user-entered text, which would be read as
     *     ESC/POS commands and could genuinely reconfigure the printer;
     *   - the rupee sign and typographic quotes, which are not in the default
     *     code page and come out as a random glyph.
     *
     * "Rs" is not a compromise anybody loves, but a price nobody can read is
     * worse than one without its symbol.
     */
    private function clean(string $text): string
    {
        $text = strtr($text, [
            '₹' => 'Rs ',
            '–' => '-',
            '—' => '-',
            '‘' => "'",
            '’' => "'",
            '“' => '"',
            '”' => '"',
            '…' => '...',
        ]);

        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $text) ?? '';
    }
}
