<?php

namespace App\Support;

/**
 * Minimal user-agent parser.
 *
 * Written rather than pulled in so the panel keeps working offline and needs
 * no extra dependency, matching how the icon set is inlined. It is a
 * heuristic, not a spec implementation: the goal is a readable
 * "Windows · Chrome 131 · Desktop" line on the sessions screen, not exact
 * identification of every crawler in existence.
 *
 * Order matters throughout - Edge advertises itself as Chrome, Chrome as
 * Safari, and most things as Mozilla, so the most specific match wins by
 * being tested first.
 */
final class Agent
{
    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public const TABLET = 'tablet';

    public const BOT = 'bot';

    public const UNKNOWN = 'unknown';

    /** Tested top to bottom; first hit wins. */
    private const BROWSERS = [
        'Edge' => '/Edg(?:e|A|iOS)?\/([\d.]+)/',
        'Opera' => '/(?:OPR|Opera)\/([\d.]+)/',
        'Samsung Internet' => '/SamsungBrowser\/([\d.]+)/',
        'Vivaldi' => '/Vivaldi\/([\d.]+)/',
        'Brave' => '/Brave\/([\d.]+)/',
        'Firefox' => '/(?:Firefox|FxiOS)\/([\d.]+)/',
        'Chrome' => '/(?:Chrome|CriOS)\/([\d.]+)/',
        'Safari' => '/Version\/([\d.]+).*Safari/',
        'Internet Explorer' => '/(?:MSIE |rv:)([\d.]+).*Trident/',
    ];

    private const PLATFORMS = [
        'Windows' => '/Windows NT ([\d.]+)/',
        'Android' => '/Android ([\d.]+)/',
        'iPadOS' => '/iPad.*OS ([\d_]+)/',
        'iOS' => '/(?:iPhone|iPod).*OS ([\d_]+)/',
        'macOS' => '/Mac OS X ([\d_.]+)/',
        'Chrome OS' => '/CrOS \w+ ([\d.]+)/',
        'Ubuntu' => '/Ubuntu(?:\/([\d.]+))?/',
        'Linux' => '/Linux/',
    ];

    /** Windows NT build number to the name people actually use. */
    private const WINDOWS_NAMES = [
        '10.0' => '10/11',
        '6.3' => '8.1',
        '6.2' => '8',
        '6.1' => '7',
    ];

    public function __construct(private readonly string $userAgent) {}

    public static function of(?string $userAgent): self
    {
        return new self(trim((string) $userAgent));
    }

    /**
     * Everything the sessions and history tables store, in one call.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        [$browser, $browserVersion] = $this->browser();
        [$platform, $platformVersion] = $this->platform();

        return [
            'device_type' => $this->deviceType(),
            'device_name' => $this->deviceName(),
            'browser' => $browser,
            'browser_version' => $browserVersion,
            'operating_system' => $platform,
            'os_version' => $platformVersion,
        ];
    }

    public function deviceType(): string
    {
        if ($this->userAgent === '') {
            return self::UNKNOWN;
        }

        if (preg_match('/bot|crawl|spider|slurp|curl|wget|python-requests|postman/i', $this->userAgent)) {
            return self::BOT;
        }

        if (preg_match('/iPad|Tablet|PlayBook|Silk|(?=.*Android)(?!.*Mobile)/i', $this->userAgent)) {
            return self::TABLET;
        }

        if (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|IEMobile/i', $this->userAgent)) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }

    /**
     * A human name for the machine.
     *
     * Browsers stopped shipping real model names years ago, so this is the
     * platform family rather than "Galaxy S24" - honest about what the
     * header actually carries.
     */
    public function deviceName(): ?string
    {
        if ($this->userAgent === '') {
            return null;
        }

        $names = [
            '/iPhone/i' => 'iPhone',
            '/iPad/i' => 'iPad',
            '/Macintosh|Mac OS X/i' => 'Mac',
            '/Windows Phone/i' => 'Windows Phone',
            '/Windows/i' => 'Windows PC',
            '/CrOS/i' => 'Chromebook',
            '/Android/i' => 'Android device',
            '/Linux/i' => 'Linux PC',
        ];

        foreach ($names as $pattern => $name) {
            if (preg_match($pattern, $this->userAgent)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null}  [name, version]
     */
    public function browser(): array
    {
        if ($this->userAgent === '') {
            return [null, null];
        }

        foreach (self::BROWSERS as $name => $pattern) {
            if (preg_match($pattern, $this->userAgent, $matches)) {
                // Major version only: "131" reads better in a table than
                // "131.0.6778.86", and the rest is noise for this purpose.
                return [$name, isset($matches[1]) ? explode('.', $matches[1])[0] : null];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: string|null, 1: string|null}  [name, version]
     */
    public function platform(): array
    {
        if ($this->userAgent === '') {
            return [null, null];
        }

        foreach (self::PLATFORMS as $name => $pattern) {
            if (! preg_match($pattern, $this->userAgent, $matches)) {
                continue;
            }

            $version = isset($matches[1]) ? str_replace('_', '.', $matches[1]) : null;

            if ($name === 'Windows') {
                $version = self::WINDOWS_NAMES[$version] ?? $version;
            }

            return [$name, $version];
        }

        return [null, null];
    }

    /** "Chrome 131 on Windows 10/11", for a one-line summary. */
    public function describe(): string
    {
        [$browser, $browserVersion] = $this->browser();
        [$platform, $platformVersion] = $this->platform();

        $left = trim(($browser ?? 'Unknown browser').' '.($browserVersion ?? ''));
        $right = trim(($platform ?? 'unknown OS').' '.($platformVersion ?? ''));

        return $left.' on '.$right;
    }
}
