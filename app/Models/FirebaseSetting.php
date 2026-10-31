<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The platform's Firebase project: what the browser needs to register for
 * notifications, and what the server needs to send them.
 *
 * ---------------------------------------------------------------------------
 * One row, the platform's
 * ---------------------------------------------------------------------------
 *
 * The SaaS has one Firebase project and every restaurant's devices register
 * against it, the same way it has one landing page. A row per tenant would
 * mean every restaurant owner creating a Google Cloud project before their
 * kitchen tablet could buzz, which none of them will do. current() returns
 * the single row, creating it empty on first read.
 *
 * ---------------------------------------------------------------------------
 * Two halves with different secrecy
 * ---------------------------------------------------------------------------
 *
 *   web config     apiKey, projectId, messagingSenderId, appId, vapidKey.
 *                  Public by design - Firebase ships them to every browser,
 *                  and they identify the project rather than authorise
 *                  anything.
 *   service account  the private key that lets the server send. Encrypted at
 *                  rest with the app key, hidden from serialisation, and
 *                  never rendered back into the settings form.
 */
class FirebaseSetting extends Model
{
    private const CACHE_KEY = 'firebase_settings.current';

    protected $fillable = [
        'is_enabled',
        'project_id', 'api_key', 'auth_domain', 'storage_bucket',
        'messaging_sender_id', 'app_id', 'measurement_id', 'vapid_key',
        'service_account', 'last_tested_at', 'last_test_result',
    ];

    protected $hidden = ['service_account'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'service_account' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * The platform's row. Read on every admin page, so cached.
     *
     * An unsaved, switched-off instance when nothing has been configured -
     * reading the settings must not write them, and the first save from the
     * settings screen is what creates the row.
     */
    public static function current(): self
    {
        return Cache::remember(self::CACHE_KEY, 3600, fn () => static::query()->orderBy('id')->first())
            ?? new static(['is_enabled' => false]);
    }

    /** Everything a browser needs to call getToken(). */
    public function hasWebConfig(): bool
    {
        return filled($this->project_id)
            && filled($this->api_key)
            && filled($this->messaging_sender_id)
            && filled($this->app_id)
            && filled($this->vapid_key);
    }

    /** Everything the server needs to send. */
    public function hasServiceAccount(): bool
    {
        $account = $this->service_account;

        return is_array($account)
            && filled($account['client_email'] ?? null)
            && filled($account['private_key'] ?? null);
    }

    /** Switched on, and both halves present. */
    public function isReady(): bool
    {
        return $this->is_enabled && $this->hasWebConfig() && $this->hasServiceAccount();
    }

    /**
     * The firebaseConfig object the JS SDK takes, verbatim.
     *
     * @return array<string, string>
     */
    public function webConfig(): array
    {
        return array_filter([
            'apiKey' => $this->api_key,
            'authDomain' => $this->auth_domain ?: ($this->project_id ? $this->project_id.'.firebaseapp.com' : null),
            'projectId' => $this->project_id,
            'storageBucket' => $this->storage_bucket,
            'messagingSenderId' => $this->messaging_sender_id,
            'appId' => $this->app_id,
            'measurementId' => $this->measurement_id,
        ]);
    }

    /** The service account's address, safe to show: it is not the key. */
    public function serviceAccountEmail(): ?string
    {
        return $this->hasServiceAccount() ? $this->service_account['client_email'] : null;
    }
}
