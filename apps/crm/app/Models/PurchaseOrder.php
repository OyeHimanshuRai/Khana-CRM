<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An order placed with a supplier.
 *
 * An intention, not an event. Nothing here moves stock or money - goods
 * receipts do that, and they point back at this.
 */
class PurchaseOrder extends Model
{
    use BelongsToShop;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const PARTIAL = 'partial';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::DRAFT => ['label' => 'Draft', 'tone' => ''],
        self::PENDING => ['label' => 'Awaiting approval', 'tone' => 'warning'],
        self::APPROVED => ['label' => 'Ordered', 'tone' => 'info'],
        self::PARTIAL => ['label' => 'Part received', 'tone' => 'warning'],
        self::RECEIVED => ['label' => 'Received', 'tone' => 'success'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
    ];

    protected $fillable = [
        'shop_id', 'supplier_id', 'warehouse_id',
        'reference', 'ordered_on', 'expected_on', 'status',
        'notes', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'ordered_on' => 'date',
            'expected_on' => 'date',
            'approved_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('reference', 'like', "%{$term}%")
            ->orWhere('notes', 'like', "%{$term}%")
            ->orWhereHas('supplier', fn (Builder $s) => $s
                ->where('name', 'like', "%{$term}%")
                ->orWhere('company', 'like', "%{$term}%")));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    /** Orders with something still to come. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::APPROVED, self::PARTIAL]);
    }

    /* --------------------------------------------------------- behaviour */

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING], true);
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PARTIAL], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    /**
     * How much of this order is still to arrive.
     */
    public function outstandingQuantity(): float
    {
        return (float) $this->items->sum(
            fn (PurchaseOrderItem $item) => $item->outstandingQuantity()
        );
    }

    /**
     * Work out the status from what has been received.
     *
     * Called after every receipt, so the order's state follows the goods
     * rather than needing anyone to remember to close it.
     */
    public function refreshReceiptStatus(): void
    {
        if (in_array($this->status, [self::DRAFT, self::PENDING, self::CANCELLED], true)) {
            return;
        }

        $items = $this->items()->get();

        $anyReceived = $items->contains(fn (PurchaseOrderItem $i) => (float) $i->received_quantity > 0.0005);
        $allReceived = $items->every(fn (PurchaseOrderItem $i) => $i->outstandingQuantity() <= 0.0005);

        $this->forceFill([
            'status' => match (true) {
                $allReceived => self::RECEIVED,
                $anyReceived => self::PARTIAL,
                default => self::APPROVED,
            },
        ])->save();
    }

    /** Mint the next shop-local reference: PO/MAIN/2026/00001. */
    public static function nextReference(Shop $shop): string
    {
        $prefix = sprintf('PO/%s/%s/', $shop->code, now()->format('Y'));

        $last = static::allShops()
            ->where('shop_id', $shop->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
