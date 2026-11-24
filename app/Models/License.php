<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform-issued license key held by one customer (tenant).
 *
 * The key itself is never stored - only `key_hash`, a keyed hash, and a
 * masked `key_hint`. Every money-like or access-like column is written by
 * LicenseService alone, so none of them is mass-assignable: a form that could
 * set `status` or `expires_at` would be a way to license yourself.
 *
 * @property int $id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class License extends Model
{
    /** Generated, not yet entered by anybody. */
    public const ISSUED = 'issued';

    /** Entered by the customer; waiting for the Super Admin. */
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    /** Switched off by the Super Admin; can be switched back on. */
    public const INACTIVE = 'inactive';

    public const BLOCKED = 'blocked';

    /** Permanently withdrawn. The key can never be used again. */
    public const REVOKED = 'revoked';

    /** States in which a customer is holding the license. */
    public const HELD = [self::PENDING, self::ACTIVE, self::INACTIVE, self::BLOCKED];

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::ISSUED => ['label' => 'Not activated', 'tone' => 'muted'],
        self::PENDING => ['label' => 'Pending Approval', 'tone' => 'warning'],
        self::ACTIVE => ['label' => 'Active', 'tone' => 'success'],
        self::INACTIVE => ['label' => 'Suspended', 'tone' => 'warning'],
        self::BLOCKED => ['label' => 'Blocked', 'tone' => 'danger'],
        self::REVOKED => ['label' => 'Revoked', 'tone' => 'danger'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'requested_at' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'blocked_at' => 'datetime',
            'last_renewed_at' => 'datetime',
            'validity_days' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class)->orderByDesc('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LicenseLog::class)->orderByDesc('id');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('key_hint', 'like', $like)
            ->orWhereHas('tenant', fn (Builder $t) => $t
                ->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('email', 'like', $like)));
    }

    /** Active licenses whose date has passed. */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    /* --------------------------------------------------------- behaviour */

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** True while the customer may use the product on the strength of this license. */
    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE && ! $this->isExpired();
    }

    public function daysLeft(): ?int
    {
        return $this->expires_at === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expires_at->copy()->startOfDay(), false);
    }

    public function isExpiringSoon(): bool
    {
        $left = $this->daysLeft();

        return $this->status === self::ACTIVE && $left !== null && $left >= 0 && $left <= (int) config('license.warn_days', 7);
    }

    /**
     * The badge a person sees: stored status, refined by the clock.
     *
     * @return array{key: string, label: string, tone: string}
     */
    public function badge(): array
    {
        $key = match (true) {
            $this->status === self::ACTIVE && $this->isExpired() => 'expired',
            $this->status === self::ACTIVE && $this->isExpiringSoon() => 'expiring',
            default => $this->status,
        };

        return match ($key) {
            'expired' => ['key' => 'expired', 'label' => 'Expired', 'tone' => 'danger'],
            'expiring' => ['key' => 'expiring', 'label' => 'Expiring Soon', 'tone' => 'warning'],
            default => ['key' => $key] + (self::STATUSES[$key] ?? ['label' => ucfirst($key), 'tone' => 'muted']),
        };
    }
}
