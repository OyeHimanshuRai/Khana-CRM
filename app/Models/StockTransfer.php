<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Stock on its way from one warehouse to another.
 *
 * Two-step by design: dispatch takes it out of the sending warehouse,
 * receipt puts it into the receiving one, and in between it is in transit
 * and countable as such. A one-step move would hide exactly the shortfall
 * that makes people ask for a transfer document in the first place.
 */
class StockTransfer extends Model
{
    use BelongsToShop;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DISPATCHED = 'dispatched';

    public const RECEIVED = 'received';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::DRAFT => ['label' => 'Draft', 'tone' => ''],
        self::PENDING => ['label' => 'Awaiting approval', 'tone' => 'warning'],
        self::APPROVED => ['label' => 'Approved', 'tone' => 'info'],
        self::DISPATCHED => ['label' => 'In transit', 'tone' => 'warning'],
        self::RECEIVED => ['label' => 'Received', 'tone' => 'success'],
        self::REJECTED => ['label' => 'Rejected', 'tone' => 'danger'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
    ];

    protected $fillable = [
        'shop_id', 'from_warehouse_id', 'to_shop_id', 'to_warehouse_id',
        'reference', 'transfer_date', 'status', 'note', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'approved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'total_quantity' => 'decimal:3',
            'total_value' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function toShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'to_shop_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('reference', 'like', "%{$term}%")
            ->orWhere('note', 'like', "%{$term}%")
            ->orWhere('created_by_name', 'like', "%{$term}%"));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    /**
     * Transfers heading into a shop, whoever raised them.
     *
     * Written against to_shop_id and without the tenant scope on purpose:
     * the sending shop owns the row, so the receiver would otherwise never
     * see the consignment it is expected to book in.
     */
    public function scopeIncomingFor(Builder $query, array $shopIds): Builder
    {
        return $query->whereIn('to_shop_id', $shopIds ?: [0]);
    }

    /* --------------------------------------------------------- behaviour */

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING], true);
    }

    public function isInTransit(): bool
    {
        return $this->status === self::DISPATCHED;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::RECEIVED, self::REJECTED, self::CANCELLED], true);
    }

    /** Whether this crosses a tenant boundary rather than moving within one. */
    public function isInterShop(): bool
    {
        return (int) $this->shop_id !== (int) $this->to_shop_id;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    /** Mint the next shop-local reference: TRF/MAIN/2026/00001. */
    public static function nextReference(Shop $shop): string
    {
        $prefix = sprintf('TRF/%s/%s/', $shop->code, now()->format('Y'));

        $last = static::allShops()
            ->where('shop_id', $shop->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
