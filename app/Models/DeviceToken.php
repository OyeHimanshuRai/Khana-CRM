<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device that can be pushed to (SRS 16).
 *
 * See the migration for why the endpoint is the identity and why the unique
 * index is on its hash.
 */
class DeviceToken extends Model
{
    use BelongsToShop;

    public const WEB_PUSH = 'web_push';

    /**
     * Firebase Cloud Messaging. The registration token goes in `endpoint` -
     * it is the device's identity in the same way a Web Push endpoint is -
     * and p256dh/auth stay empty, because FCM does its own encryption.
     */
    public const FCM = 'fcm';

    protected $fillable = [
        'shop_id', 'user_id', 'kind',
        'endpoint', 'endpoint_hash', 'p256dh', 'auth',
        'label', 'user_agent', 'last_used_at',
    ];

    /**
     * The endpoint is a secret in the sense that matters: anybody holding it
     * can send a notification to that browser. It never needs to leave the
     * server, so it never does.
     */
    protected $hidden = ['endpoint', 'endpoint_hash', 'p256dh', 'auth'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeWebPush(Builder $query): Builder
    {
        return $query->where('kind', self::WEB_PUSH);
    }

    /** The identity, hashed. */
    public static function hashFor(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** What to call this screen on a list of four tablets. */
    public function displayLabel(): string
    {
        return $this->label ?: ($this->user?->name ?? 'A device');
    }
}
