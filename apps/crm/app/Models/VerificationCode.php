<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A one-time code that was sent to somebody (§3.8).
 *
 * The code itself is never here - only its hash. See the migration.
 *
 * @property string $destination
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $verified_at
 */
class VerificationCode extends Model
{
    public const TABLE_SESSION = 'table_session';

    protected $fillable = [
        'verifiable_type', 'verifiable_id',
        'purpose', 'channel', 'destination', 'code_hash',
        'attempts', 'expires_at', 'verified_at', 'sent_at', 'ip',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function verifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------ scopes */

    /** Codes that could still be used: unspent, unexpired, unburnt. */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', (int) config('sms.otp.max_attempts', 5));
    }

    /* --------------------------------------------------------- behaviour */

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isSpent(): bool
    {
        return $this->verified_at !== null;
    }

    public function isBurnt(): bool
    {
        return $this->attempts >= (int) config('sms.otp.max_attempts', 5);
    }

    /** Seconds until another code may be sent to this number. */
    public function resendWaitSeconds(): int
    {
        $wait = (int) config('sms.otp.resend_seconds', 60);

        $elapsed = $this->created_at?->diffInSeconds(now()) ?? $wait;

        return (int) max(0, $wait - $elapsed);
    }

    /**
     * The number with most of it hidden, for telling a guest where it went.
     *
     * "we sent it to •••••• 3421" is enough for somebody to know whether
     * they typed their own number correctly, and not enough to be worth
     * reading over a shoulder.
     */
    public function maskedDestination(): string
    {
        $digits = preg_replace('/\D/', '', $this->destination) ?? '';

        return strlen($digits) <= 4
            ? $digits
            : str_repeat('•', strlen($digits) - 4).' '.substr($digits, -4);
    }
}
