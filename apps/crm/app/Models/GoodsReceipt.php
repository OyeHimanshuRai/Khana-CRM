<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * What arrived, and what the supplier billed for it.
 *
 * One document for both - see the migration. Posting it is what puts stock
 * on the shelf and money on the supplier's account; before that it is a
 * draft and changes nothing.
 */
class GoodsReceipt extends Model
{
    use BelongsToShop;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::DRAFT => ['label' => 'Draft', 'tone' => 'warning'],
        self::POSTED => ['label' => 'Received', 'tone' => 'success'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
    ];

    protected $fillable = [
        'shop_id', 'supplier_id', 'warehouse_id', 'purchase_order_id',
        'reference', 'received_on', 'status',
        'bill_number', 'bill_date', 'due_date',
        'other_charges', 'is_inter_state', 'notes',
    ];

    /*
     | Every total is computed by PurchaseService when the receipt is
     | posted, never mass-assigned.
     */

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'bill_date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'is_inter_state' => 'boolean',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'due_total' => 'decimal:2',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'reference')->orderBy('paid_at');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('reference', 'like', "%{$term}%")
            ->orWhere('bill_number', 'like', "%{$term}%")
            ->orWhereHas('supplier', fn (Builder $s) => $s
                ->where('name', 'like', "%{$term}%")
                ->orWhere('company', 'like', "%{$term}%")));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('received_on', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('received_on', '<=', $d));
    }

    /** Bills that count towards what the shop owes. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', self::POSTED);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->counted()->where('due_total', '>', 0);
    }

    /* --------------------------------------------------------- behaviour */

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    public function isOverdue(): bool
    {
        return $this->isPosted()
            && (float) $this->due_total > 0.004
            && $this->due_date !== null
            && $this->due_date->isBefore(today());
    }

    /** Mint the next shop-local reference: GRN/MAIN/2026/00001. */
    public static function nextReference(Shop $shop): string
    {
        $prefix = sprintf('GRN/%s/%s/', $shop->code, now()->format('Y'));

        $last = static::allShops()
            ->where('shop_id', $shop->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
