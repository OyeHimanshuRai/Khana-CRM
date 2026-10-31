<?php

namespace App\Support;

use App\Models\Alert;
use App\Models\User;

/**
 * Who wants to hear about what, and how loudly.
 *
 * Per person, per kind of event, three switches:
 *
 *   alert   a toast on screen when it happens (it is always in the bell's
 *           list - switching this off makes it quiet, not hidden)
 *   sound   a chime, and the spoken announcement if voice is on
 *   push    a notification on this person's phone or laptop (Firebase)
 *
 * Plus four that apply to everything: sound at all, voice at all, which
 * language the voice speaks, and how loud.
 *
 * Everything defaults ON. A restaurant that never opens this screen must
 * still hear its new orders; turning things off is a choice somebody makes,
 * never the starting state. Stored on users.notification_preferences, and a
 * null there means "never changed anything".
 */
final class NotificationPreferences
{
    /** The kitchen board's own event: a ticket landing on the pass. */
    public const KITCHEN_TICKET = 'kitchen_ticket';

    public const CHANNELS = ['alert', 'sound', 'push'];

    public const LANGUAGES = [
        'en-IN' => 'English',
        'hi-IN' => 'हिन्दी (Hindi)',
    ];

    /**
     * Every kind of event a person can tune, in the order the screen lists
     * them. The bell's own types, and the kitchen board's ticket.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function types(): array
    {
        return [self::KITCHEN_TICKET => ['label' => 'Kitchen ticket (kitchen screen)', 'icon' => 'clock']]
            + Alert::TYPES;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'sound' => true,
            'voice' => true,
            'voice_lang' => 'en-IN',
            'volume' => 80,
            'types' => array_map(
                fn () => ['alert' => true, 'sound' => true, 'push' => true],
                self::types(),
            ),
        ];
    }

    /**
     * This person's settings, with anything they never set filled in.
     *
     * Merged per type, so a kind of event added next month is on for
     * everybody rather than missing for everybody who saved before it existed.
     *
     * @return array<string, mixed>
     */
    public static function for(?User $user): array
    {
        $defaults = self::defaults();
        $saved = (array) ($user?->notification_preferences ?? []);

        $prefs = [
            'sound' => (bool) ($saved['sound'] ?? $defaults['sound']),
            'voice' => (bool) ($saved['voice'] ?? $defaults['voice']),
            'voice_lang' => array_key_exists($saved['voice_lang'] ?? '', self::LANGUAGES) ? $saved['voice_lang'] : $defaults['voice_lang'],
            'volume' => max(0, min(100, (int) ($saved['volume'] ?? $defaults['volume']))),
            'types' => [],
        ];

        foreach ($defaults['types'] as $type => $channels) {
            foreach (self::CHANNELS as $channel) {
                $prefs['types'][$type][$channel] = (bool) ($saved['types'][$type][$channel] ?? $channels[$channel]);
            }
        }

        return $prefs;
    }

    /** Does this person want this kind of event on this channel? */
    public static function wants(?User $user, string $type, string $channel): bool
    {
        $prefs = self::for($user);

        if ($channel === 'sound' && ! $prefs['sound']) {
            return false;
        }

        // A type the catalogue does not know (an old alert row) behaves as on.
        return $prefs['types'][$type][$channel] ?? true;
    }

    /**
     * Turn what the form posted into what is stored.
     *
     * Checkboxes only send the boxes that are ticked, so every type and
     * channel is written explicitly - an unticked box has to be stored as
     * false, or it would fall back to the default and switch itself back on.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function fromInput(array $input): array
    {
        $types = [];

        foreach (array_keys(self::types()) as $type) {
            foreach (self::CHANNELS as $channel) {
                $types[$type][$channel] = filter_var($input['types'][$type][$channel] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return [
            'sound' => filter_var($input['sound'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'voice' => filter_var($input['voice'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'voice_lang' => array_key_exists($input['voice_lang'] ?? '', self::LANGUAGES) ? $input['voice_lang'] : 'en-IN',
            'volume' => max(0, min(100, (int) ($input['volume'] ?? 80))),
            'types' => $types,
        ];
    }
}
