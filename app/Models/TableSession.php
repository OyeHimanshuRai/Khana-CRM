<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sitting at one table.
 *
 * Written only by App\Services\TableSessionService - opening and closing have
 * to hold "at most one open session per table", and that invariant lives in
 * one place.
 */
class TableSession extends Model
{
    use BelongsToShop;

    public const OPEN = 'open';

    public const BILLED = 'billed';

    public const CLOSED = 'closed';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::OPEN => ['label' => 'Seated', 'tone' => 'warning'],
        self::BILLED => ['label' => 'Billing', 'tone' => 'brand'],
        self::CLOSED => ['label' => 'Closed', 'tone' => ''],
    ];

    protected $fillable = [
        'shop_id', 'restaurant_table_id', 'table_qr_id', 'token', 'status',
        'customer_id', 'guest_name', 'guest_mobile', 'mobile_verified_at', 'covers',
        'opened_at', 'last_activity_at', 'closed_at', 'closed_reason',
    ];

    protected function casts(): array
    {
        return [
            'covers' => 'integer',
            'mobile_verified_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    public function qr(): BelongsTo
    {
        return $this->belongsTo(TableQr::class, 'table_qr_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The bills raised against this sitting (§6).
     *
     * Plural, because a split bill is several invoices off one table. The
     * column that used to sit here on `table_sessions` could not say that and
     * was removed - see the migration.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'table_session_id')->orderBy('id');
    }

    /** What the table has picked but not yet sent to the kitchen. */
    public function cartItems(): HasMany
    {
        return $this->hasMany(TableCartItem::class)->orderBy('id');
    }

    /** Everything this sitting has sent, newest first. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderByDesc('id');
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Sessions that are still taking orders.
     *
     * `billed` is deliberately not live: once the bill is raised, adding to
     * it silently would mean the guest pays for something the printed total
     * did not include.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function scopeOpenOrBilled(Builder $query): Builder
    {
        return $query->whereIn('status', [self::OPEN, self::BILLED]);
    }

    /* --------------------------------------------------------- behaviour */

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst((string) $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    /** What to call the party on a KOT or the floor plan. */
    public function partyName(): string
    {
        return $this->guest_name
            ?: ($this->customer?->name ?: 'Guest');
    }

    /** How long they have been sitting, for the floor plan and the KDS. */
    public function seatedMinutes(): int
    {
        $from = $this->opened_at ?? $this->created_at;

        return $from ? (int) $from->diffInMinutes(now()) : 0;
    }
}
