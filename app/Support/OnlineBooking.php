<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Carbon;

// An outlet's online table-booking rules, stored as JSON on the shop and always read through here.
final class OnlineBooking
{
    public const DEPOSIT_NONE = 'none';

    public const DEPOSIT_FIXED = 'fixed';

    public const DEPOSIT_PER_PERSON = 'per_person';

    public const DEPOSIT_MODES = [self::DEPOSIT_NONE, self::DEPOSIT_FIXED, self::DEPOSIT_PER_PERSON];

    public const DEFAULTS = [
        'enabled' => false,
        'opens' => '12:00',
        'closes' => '23:00',
        // 0 = Sunday ... 6 = Saturday
        'days' => [0, 1, 2, 3, 4, 5, 6],
        'slot_minutes' => 30,
        'duration_minutes' => 90,
        'max_party' => 10,
        'lead_minutes' => 60,
        'window_days' => 30,
        'deposit_mode' => self::DEPOSIT_NONE,
        'deposit_amount' => 0,
        'cancel_hours' => 24,
        'hold_minutes' => 10,
    ];

    /** @return array<string, mixed> */
    public static function settings(Shop $shop): array
    {
        $saved = is_array($shop->online_booking) ? $shop->online_booking : [];

        return array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    // Open to guests: switched on, and the outlet runs tables (dining) on its storefront.
    public static function isOpen(Shop $shop): bool
    {
        return (bool) self::settings($shop)['enabled'] && in_array('dining', Modules::forShop($shop), true);
    }

    public static function depositFor(array $settings, int $partySize): float
    {
        $amount = round((float) $settings['deposit_amount'], 2);

        return match ($settings['deposit_mode']) {
            self::DEPOSIT_FIXED => $amount,
            self::DEPOSIT_PER_PERSON => round($amount * $partySize, 2),
            default => 0.0,
        };
    }

    // The outlet's wall-clock "now"; reservations store wall-clock times, as the admin screens enter them.
    public static function now(Shop $shop): Carbon
    {
        $zone = $shop->timezone ?: 'Asia/Kolkata';

        return Carbon::parse(now($zone)->format('Y-m-d H:i:s'));
    }

    /** @return array<string, mixed> validation rules for the settings form */
    public static function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'opens' => ['required', 'date_format:H:i'],
            'closes' => ['required', 'date_format:H:i', 'after:opens'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:0,6'],
            'slot_minutes' => ['required', 'integer', 'in:15,30,45,60'],
            'duration_minutes' => ['required', 'integer', 'between:30,300'],
            'max_party' => ['required', 'integer', 'between:1,50'],
            'lead_minutes' => ['required', 'integer', 'between:0,1440'],
            'window_days' => ['required', 'integer', 'between:1,180'],
            'deposit_mode' => ['required', 'in:'.implode(',', self::DEPOSIT_MODES)],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'max:100000', 'required_unless:deposit_mode,'.self::DEPOSIT_NONE],
            'cancel_hours' => ['required', 'integer', 'between:0,168'],
            'hold_minutes' => ['required', 'integer', 'between:5,30'],
        ];
    }

    /** @param  array<string, mixed>  $data  validated form input */
    public static function normalise(array $data): array
    {
        $clean = array_intersect_key($data, self::DEFAULTS);
        $clean['enabled'] = (bool) ($data['enabled'] ?? false);
        $clean['days'] = array_values(array_unique(array_map('intval', $data['days'] ?? [])));
        sort($clean['days']);
        foreach (['slot_minutes', 'duration_minutes', 'max_party', 'lead_minutes', 'window_days', 'cancel_hours', 'hold_minutes'] as $key) {
            $clean[$key] = (int) $clean[$key];
        }
        $clean['deposit_amount'] = $clean['deposit_mode'] === self::DEPOSIT_NONE ? 0 : round((float) ($data['deposit_amount'] ?? 0), 2);

        return array_merge(self::DEFAULTS, $clean);
    }
}
