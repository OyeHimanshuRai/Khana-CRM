<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An IP block, enforced by EnsureIpNotBlocked on the login route.
 *
 * user_id scopes it: set, only that account is stopped from that address;
 * null, the address is refused for everyone.
 */
class BlockedIp extends Model
{
    public const ACTIVE = 'active';

    public const LIFTED = 'lifted';

    protected $fillable = [
        'ip_address', 'user_id', 'reason',
        'blocked_by', 'blocked_by_name',
        'blocked_at', 'expires_at', 'status', 'lifted_at',
    ];

    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
            'expires_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    /**
     * Blocks that are still biting: not lifted, and not past their expiry.
     */
    public function scopeEnforced(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    public function isEnforced(): bool
    {
        return $this->status === self::ACTIVE
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isPermanent(): bool
    {
        return $this->expires_at === null;
    }

    /**
     * Is this address barred for this account?
     *
     * A global block (user_id null) counts for everyone; a scoped one only
     * for the account it names.
     */
    public static function blocks(string $ip, ?User $user = null): ?self
    {
        return static::query()
            ->enforced()
            ->where('ip_address', $ip)
            ->where(function (Builder $query) use ($user) {
                $query->whereNull('user_id');

                if ($user) {
                    $query->orWhere('user_id', $user->id);
                }
            })
            // A global block outranks a scoped one when both exist.
            ->orderByRaw('user_id IS NULL DESC')
            ->first();
    }

    public function lift(): void
    {
        $this->forceFill([
            'status' => self::LIFTED,
            'lifted_at' => now(),
        ])->save();
    }

    public function describeScope(): string
    {
        return $this->user_id === null ? 'All accounts' : 'This account only';
    }

    public function describeDuration(): string
    {
        if ($this->isPermanent()) {
            return 'Permanent';
        }

        return $this->expires_at->isPast()
            ? 'Expired '.$this->expires_at->diffForHumans()
            : 'Until '.$this->expires_at->format('d M Y, H:i');
    }
}
