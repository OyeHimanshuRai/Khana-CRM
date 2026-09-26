<?php

namespace Khana\Kit\Ui;

/**
 * The one look every Khana product shares: today's CSS and plain JavaScript
 * components, kept in packages/kit/ui and served to each app from
 * public/vendor/khana.
 *
 * `php artisan vendor:publish --tag=khana-ui --force` copies them there; each
 * app runs it after every composer install, so a deploy always carries the
 * kit version it was built with. Until then, AssetController serves the same
 * files straight from the kit, so a fresh checkout never renders unstyled.
 */
final class Ui
{
    /** Loaded on every signed-in page, in this order. */
    public const STYLES = ['css/theme.css', 'css/layout.css', 'css/app.css'];

    /**
     * The shared behaviours. Each one does nothing on a page without its
     * markup, so loading them everywhere costs a cached request, not a bug.
     */
    public const SCRIPTS = [
        'js/app.js', 'js/layout.js', 'js/modal.js', 'js/tabs.js',
        'js/permission-matrix.js', 'js/ajax-list.js',
        'js/settings.js', 'js/user-security.js', 'js/crud-forms.js',
        'js/line-items.js', 'js/api-tokens.js', 'js/realtime.js',
    ];

    /** Where the files live inside the kit. */
    public static function path(string $file = ''): string
    {
        $root = dirname(__DIR__, 2).'/ui';

        return $file === '' ? $root : $root.'/'.ltrim($file, '/');
    }

    /**
     * The public URL of one kit file, versioned by the kit's own copy so a
     * changed file is fetched again without a build step.
     */
    public static function asset(string $file): string
    {
        $file = ltrim($file, '/');
        $source = self::path($file);

        return asset('vendor/khana/'.$file).(is_file($source) ? '?v='.filemtime($source) : '');
    }

    /**
     * The product this app is: Accounts, CRM or ERP.
     */
    public static function productName(?string $service = null): string
    {
        return match ($service ?? config('khana.service')) {
            'accounts' => 'Accounts',
            'crm' => 'CRM',
            'erp' => 'ERP',
            default => (string) config('app.name', 'Khana'),
        };
    }

    /** The first letters of a name, for an avatar or a lettermark. */
    public static function initials(?string $name): string
    {
        $words = preg_split('/\s+/', trim((string) $name)) ?: [];
        $letters = array_map(fn (string $word) => mb_substr($word, 0, 1), array_slice(array_filter($words), 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }
}
