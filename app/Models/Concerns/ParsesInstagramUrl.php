<?php

namespace App\Models\Concerns;

/**
 * Instagram shortcode handling, shared by Reel and InstagramPost.
 *
 * Instagram identifies a post by an eleven-ish character shortcode in the
 * URL - /p/, /reel/ and /tv/ all carry it. That code is what an embed is
 * built from, so it is pulled out once on save rather than re-parsed on
 * every read.
 */
trait ParsesInstagramUrl
{
    /** Path segments Instagram uses for a single piece of media. */
    private const SEGMENTS = ['p', 'reel', 'reels', 'tv'];

    /**
     * The shortcode in an Instagram URL, or null if there is not one.
     *
     * Tolerant of query strings, trailing slashes and the www-less form,
     * because those are all what people actually paste.
     */
    public static function shortcodeFrom(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        $path = parse_url(trim($url), PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $parts = array_values(array_filter(explode('/', $path)));

        foreach ($parts as $index => $part) {
            if (! in_array(strtolower($part), self::SEGMENTS, true)) {
                continue;
            }

            $code = $parts[$index + 1] ?? null;

            // Shortcodes are URL-safe base64: letters, digits, - and _.
            return is_string($code) && preg_match('/^[A-Za-z0-9_-]{5,30}$/', $code)
                ? $code
                : null;
        }

        return null;
    }

    /**
     * The oEmbed-style URL for this item's shortcode.
     *
     * Instagram serves /embed for every media type, so the /p/ form works
     * for reels too - no need to remember which segment the original used.
     */
    public function embedUrl(): ?string
    {
        $code = $this->shortcode();

        return $code ? "https://www.instagram.com/p/{$code}/embed" : null;
    }

    /** A canonical permalink, rebuilt from the shortcode. */
    public function permalink(): ?string
    {
        $code = $this->shortcode();

        return $code ? "https://www.instagram.com/p/{$code}/" : null;
    }

    /**
     * The stored shortcode.
     *
     * Each model names its own column, so this is the one thing the trait
     * cannot assume.
     */
    abstract public function shortcode(): ?string;
}
