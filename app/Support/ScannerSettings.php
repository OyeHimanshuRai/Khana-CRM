<?php

namespace App\Support;

/**
 * The barcode scanner's settings, resolved.
 *
 * Settings > Company Settings > Barcode Scanner stores thirteen flat strings
 * (see config/company_settings.php). Almost nothing wants them flat: what a
 * caller actually asks is "should I render the camera button", "which
 * symbologies do I hand the decoder", "is the wireless scan field live" -
 * and each of those is more than one stored key ANDed together.
 *
 * This is where that arithmetic lives, once. The alternative - each blade
 * re-deriving `$enabled && $camera === '1' && $mode !== 'wireless'` - is how
 * a screen ends up disagreeing with the endpoint about whether scanning is
 * on, which is precisely the bug a shop reports as "the button is there but
 * it says scanning is off".
 *
 * Both input methods converge on ScannerService::lookup() and from there on
 * one cart, one invoice, one stock movement. Nothing in this class forks that
 * - it only decides which methods are offered.
 *
 * @see \App\Services\ScannerService  the server side of a scan
 * @see public/assets/js/scanner.js   the browser side, fed by forJs()
 */
final class ScannerSettings
{
    public const MODE_WIRELESS = 'wireless';

    public const MODE_CAMERA = 'camera';

    public const MODE_BOTH = 'both';

    /**
     * Symbology names for each "Scanner Type", spelled as html5-qrcode's
     * Html5QrcodeSupportedFormats enum keys - scanner.js maps them to the
     * numeric enum values the library actually wants.
     *
     * Names rather than numbers deliberately: the numbers are an
     * implementation detail of a third-party library that may renumber them,
     * and a name that library drops fails visibly in one place (scanner.js
     * skips unknown keys) instead of silently selecting the wrong format.
     *
     * @var array<string, array<int, string>>
     */
    private const FORMATS = [
        '1d' => [
            'EAN_13', 'EAN_8', 'UPC_A', 'UPC_E', 'UPC_EAN_EXTENSION',
            'CODE_128', 'CODE_39', 'CODE_93', 'ITF', 'CODABAR',
        ],
        '2d' => ['QR_CODE', 'DATA_MATRIX', 'PDF_417', 'AZTEC'],
        'qr' => ['QR_CODE'],
    ];

    /**
     * Per-request memo. CompanySettings::values() walks every section's
     * fields; the POS page asks about the scanner several times while
     * rendering and should pay for that once.
     *
     * @var array<string, string|null>|null
     */
    private static ?array $memo = null;

    /**
     * Every scanner_* setting, with each field's documented default filled in
     * for a shop that has never opened the settings screen.
     *
     * @return array<string, string|null>
     */
    public static function values(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $all = CompanySettings::values();
        $fields = CompanySettings::section('scanner')['fields'] ?? [];

        return self::$memo = collect($fields)
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => $all[$key] ?? null])
            ->all();
    }

    /**
     * Drop the memo so the next read hits the settings table again.
     *
     * Only tests and long-lived workers need this; a normal request reads
     * settings after any save it performs has already finished.
     */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /** The master switch. Off, neither input method is offered anywhere. */
    public static function enabled(): bool
    {
        return self::on('scanner_enabled');
    }

    public static function mode(): string
    {
        $mode = self::values()['scanner_mode'] ?? self::MODE_BOTH;

        return in_array($mode, [self::MODE_WIRELESS, self::MODE_CAMERA, self::MODE_BOTH], true)
            ? $mode
            : self::MODE_BOTH;
    }

    /**
     * Is the keyboard-wedge scan field live?
     *
     * A 2.4GHz wireless, Bluetooth or wired USB scanner in HID mode is a
     * keyboard as far as the browser is concerned, so "live" means nothing
     * more than: POS keeps a focused field that treats a fast burst ending in
     * Enter as a scan.
     */
    public static function wirelessEnabled(): bool
    {
        return self::enabled()
            && self::on('scanner_hardware_input')
            && self::mode() !== self::MODE_CAMERA;
    }

    /** Is the camera button offered, and will the camera endpoint be used? */
    public static function cameraEnabled(): bool
    {
        return self::enabled()
            && self::on('scanner_camera_enabled')
            && self::mode() !== self::MODE_WIRELESS;
    }

    /**
     * Symbology names for the configured "Scanner Type".
     *
     * @return array<int, string>
     */
    public static function formats(): array
    {
        $type = self::values()['scanner_formats'] ?? 'all';

        if ($type === 'all') {
            // array_values, not the raw merge: scanner.js indexes this as a
            // JSON array and a sparse one would serialise as an object.
            return array_values(array_unique([...self::FORMATS['1d'], ...self::FORMATS['2d']]));
        }

        return self::FORMATS[$type] ?? array_values(array_unique([...self::FORMATS['1d'], ...self::FORMATS['2d']]));
    }

    /**
     * The blob public/assets/js/scanner.js reads from
     * data-scanner-settings.
     *
     * Printed into the page rather than fetched, so opening the camera never
     * waits on a settings round trip - and so a decoded barcode is acted on
     * in the same tick it was read.
     *
     * @return array<string, mixed>
     */
    public static function forJs(): array
    {
        // No auto_enter here on purpose: it governs the search box, which is
        // line-items.js's territory, and reaches it as data-scanner-auto-enter
        // alongside data-scanner-auto-detect. A camera scan that lands in that
        // box is written programmatically, which fires no 'input' event, so it
        // waits for a real keystroke either way - see autoEnter() below.
        return [
            'camera_enabled' => self::cameraEnabled(),
            'formats' => self::formats(),
            'auto_add' => self::on('scanner_auto_add'),
            'duplicate_qty_increment' => self::on('scanner_duplicate_qty_increment'),
            'success_sound' => self::on('scanner_success_sound'),
            'error_sound' => self::on('scanner_error_sound'),
            'vibrate' => self::on('scanner_vibrate'),
        ];
    }

    /** Should the scan field hold focus between scans? */
    public static function autoFocus(): bool
    {
        return self::wirelessEnabled() && self::on('scanner_auto_focus');
    }

    /**
     * Should a fast burst of keystrokes ending in Enter be read as a scan?
     *
     * Gated on the wireless method being live, because a keystroke burst is
     * the only thing that method produces - with it off, Enter in the search
     * box is just Enter.
     */
    public static function autoDetect(): bool
    {
        return self::wirelessEnabled() && self::on('scanner_auto_detect');
    }

    /**
     * Should an exact code match commit itself, without an Enter?
     *
     * Not gated on either method: the camera and the wireless field both feed
     * the same search box, and an operator typing a barcode by hand is
     * entitled to the same behaviour.
     */
    public static function autoEnter(): bool
    {
        return self::on('scanner_auto_enter');
    }

    private static function on(string $key): bool
    {
        return (self::values()[$key] ?? null) === '1';
    }
}
