<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody asking for a demo (§19).
 *
 * The one row in this feature that is not content: it is a sales lead, written
 * from the public internet, and losing one costs money. Everything about it is
 * shaped by that.
 *
 * No BelongsToShop and no tenant: whoever filled this in is not a customer
 * yet. That is the whole point of the form.
 */
class DemoRequest extends Model
{
    /** Nobody has looked at it. */
    public const NEW = 'new';

    /** Somebody has been in touch. */
    public const CONTACTED = 'contacted';

    /** They signed up. */
    public const CONVERTED = 'converted';

    /** Dead, or spam. */
    public const CLOSED = 'closed';

    public const STATUSES = [
        self::NEW => 'New',
        self::CONTACTED => 'Contacted',
        self::CONVERTED => 'Converted',
        self::CLOSED => 'Closed',
    ];

    protected $fillable = [
        'name', 'email', 'phone', 'city', 'business_name',
        'outlets', 'message', 'status', 'ip_address',
        'handled_by', 'handled_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'outlets' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /* ------------------------------------------------------------ scopes */

    /** The only list that matters on a Monday morning. */
    public function scopeUnhandled(Builder $query): Builder
    {
        return $query->where('status', self::NEW);
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return $status && isset(self::STATUSES[$status])
            ? $query->where('status', $status)
            : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%")
            ->orWhere('business_name', 'like', "%{$term}%")
            ->orWhere('city', 'like', "%{$term}%"));
    }

    /* --------------------------------------------------------- behaviour */

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::NEW => 'warning',
            self::CONTACTED => 'info',
            self::CONVERTED => 'success',
            default => 'default',
        };
    }

    /**
     * The best way to reach them.
     *
     * Phone first: this is an Indian restaurant sale and it closes on a call,
     * not in an inbox.
     */
    public function contact(): ?string
    {
        return $this->phone ?: $this->email;
    }

    /** How long it has been sitting there unanswered. */
    public function waitingHours(): ?int
    {
        return $this->status === self::NEW
            ? (int) $this->created_at->diffInHours(now())
            : null;
    }
}
