<?php

namespace App\Models;

use App\Support\Outlets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Khana\Kit\Tenancy\BelongsToTenant;

/**
 * Stock sent from one outlet of a business to another. Written by
 * App\Services\Transfers together with the ledger rows it causes.
 *
 * Scoped to the business rather than to an outlet, because it belongs to
 * two: `touching()` is what narrows it to the outlet somebody is working in.
 */
class StockTransfer extends Model
{
    use BelongsToTenant;

    public const SENT = 'sent';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    /** status => [label, badge tone] */
    public const STATUSES = [
        self::SENT => ['On its way', 'warning'],
        self::RECEIVED => ['Received', 'success'],
        self::CANCELLED => ['Cancelled', 'danger'],
    ];

    protected $fillable = [
        'tenant_id', 'from_outlet_id', 'to_outlet_id', 'number', 'from_store_id', 'to_store_id', 'status',
        'sent_on', 'received_on', 'note', 'value_paise', 'short_paise', 'user_id', 'received_by',
    ];

    protected $attributes = [
        'status' => self::SENT,
        'value_paise' => 0,
        'short_paise' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sent_on' => 'date',
            'received_on' => 'date',
            'value_paise' => 'integer',
            'short_paise' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('id');
    }

    /*
    | The two stores are read past the outlet scope: whoever may see the
    | transfer may see where it left from and where it was put, though one
    | of the two is in an outlet they do not work in.
    */
    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'from_store_id')->withoutGlobalScopes();
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'to_store_id')->withoutGlobalScopes();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** The transfers one outlet sent or is being sent. */
    public function scopeTouching(Builder $query, string $outletId): Builder
    {
        return $query->where(fn (Builder $either) => $either
            ->where('from_outlet_id', $outletId)
            ->orWhere('to_outlet_id', $outletId));
    }

    /** Sent to this outlet and not yet taken in. */
    public function scopeAwaitedAt(Builder $query, string $outletId): Builder
    {
        return $query->where('to_outlet_id', $outletId)->where('status', self::SENT);
    }

    public function isOnItsWay(): bool
    {
        return $this->status === self::SENT;
    }

    public function isComingTo(string $outletId): bool
    {
        return $this->to_outlet_id === $outletId;
    }

    public function fromName(): string
    {
        return Outlets::name($this->from_outlet_id);
    }

    public function toName(): string
    {
        return Outlets::name($this->to_outlet_id);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'info';
    }
}
