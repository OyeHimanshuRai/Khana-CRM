<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Where the payment keys actually come from (§11).
 *
 * ---------------------------------------------------------------------------
 * The environment wins
 * ---------------------------------------------------------------------------
 *
 * A value pinned in .env is not overridden by somebody pasting into a form.
 * That is the right way round: an environment variable is a deployment
 * decision, made deliberately, often by a different person, and a settings
 * screen that could silently undo it would make a production key something
 * anybody with an admin login could change without anybody knowing.
 *
 * Where .env says nothing, the settings screen is the answer - which is the
 * ordinary case, because the person holding the Razorpay keys is the
 * restaurant's owner and the person who can edit .env is whoever deployed it.
 *
 * ---------------------------------------------------------------------------
 * Read through config, always
 * ---------------------------------------------------------------------------
 *
 * Nothing outside this class reads `Setting::get('razorpay_secret')`. It is
 * merged into config('payments') once per request, so every caller - the
 * gateway, the manager, a console command - sees one answer, and a test can
 * override it the same way it overrides anything else.
 */
class PaymentSettings
{
    /**
     * Fold the saved settings into config('payments'), where .env is silent.
     *
     * Called from AppServiceProvider::boot(). Cheap: Setting::map() is a
     * cached array, and this touches four keys.
     */
    public static function apply(): void
    {
        /*
         | The database may not exist yet - during `migrate` on a fresh
         | install, or in a container starting before its database. A
         | settings read that throws there would make every artisan command
         | fail, so silence is the correct answer and the defaults stand.
         */
        try {
            $stored = Setting::map();
        } catch (\Throwable $e) {
            return;
        }

        $gateway = self::pick('PAYMENT_GATEWAY', $stored, 'payment_gateway');

        if (filled($gateway)) {
            config(['payments.gateway' => $gateway]);
        }

        foreach ([
            'razorpay.key' => ['RAZORPAY_KEY', 'razorpay_key'],
            'razorpay.secret' => ['RAZORPAY_SECRET', 'razorpay_secret'],
            'razorpay.webhook_secret' => ['RAZORPAY_WEBHOOK_SECRET', 'razorpay_webhook_secret'],
        ] as $path => [$env, $key]) {
            $value = self::pick($env, $stored, $key);

            if (filled($value)) {
                config(['payments.gateways.'.$path => $value]);
            }
        }
    }

    /**
     * The environment's value, or the stored one.
     *
     * @param  array<string, string|null>  $stored
     */
    private static function pick(string $env, array $stored, string $key): ?string
    {
        $fromEnv = env($env);

        return filled($fromEnv) ? (string) $fromEnv : ($stored[$key] ?? null);
    }

    /**
     * Which of these are locked by the environment, for the settings screen.
     *
     * A field somebody can type into that will then be ignored is worse than
     * a field that says why it is disabled.
     *
     * @return array<int, string>
     */
    public static function lockedByEnvironment(): array
    {
        $locked = [];

        foreach ([
            'payment_gateway' => 'PAYMENT_GATEWAY',
            'razorpay_key' => 'RAZORPAY_KEY',
            'razorpay_secret' => 'RAZORPAY_SECRET',
            'razorpay_webhook_secret' => 'RAZORPAY_WEBHOOK_SECRET',
        ] as $key => $env) {
            if (filled(env($env))) {
                $locked[] = $key;
            }
        }

        return $locked;
    }
}
