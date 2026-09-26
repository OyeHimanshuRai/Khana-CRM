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
 * Goods coming back from a customer.
 *
 * Approving is what moves anything: until then it is a claim, not an event.
 */
class SalesReturn extends Model
{
    use BelongsToShop;

    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::DRAFT => ['label' => 'Draft', 'tone' => ''],
        self::PENDING => ['label' => 'Awaiting approval', 'tone' => 'warning'],
        self::APPROVED => ['label' => 'Accepted', 'tone' => 'success'],
        self::REJECTED => ['label' => 'Refused', 'tone' => 'danger'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
    ];

    /* How the customer was made whole. */
    public const CREDIT = 'credit';

    public const REFUND = 'refund';

    public const NONE = 'none';

    /** @var array<string, string> */
    public const SETTLEMENTS = [
        self::CREDIT => 'Credited to their account',
        self::REFUND => 'Money handed back',
        self::NONE => 'Exchanged, nothing changes hands',
    ];

    /** @var array<string, string> */
    public const REASONS = [
        'damaged' => 'Damaged or leaking',
        'expired' => 'Expired',
        'wrong_item' => 'Wrong item supplied',
        'not_needed' => 'No longer needed',
        'quality' => 'Quality complaint',
        'excess' => 'Over-supplied',
        'other' => 'Other',
    ];

    protected $fillable = [
        'shop_id', 'warehouse_id', 'invoice_id', 'customer_id', 'customer_name',
        'reference', 'returned_on', 'status',
        'reason_code', 'reason', 'settlement', 'review_note',
    ];

    /* Totals and every by/at column are written by the service. */

    protected function casts(): array
    {
        return [
            'returned_on' => 'date',
            'approved_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'cost_total' => 'decimal:4',
            'refund_amount' => 'decimal:2',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'reference');
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
            ->orWhere('customer_name', 'like', "%{$term}%")
            ->orWhere('reason', 'like', "%{$term}%")
            ->orWhereHas('invoice', fn (Builder $i) => $i->where('number', 'like', "%{$term}%")));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('returned_on', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('returned_on', '<=', $d));
    }

    /** Returns that count - the ones that actually happened. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /* --------------------------------------------------------- behaviour */

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING], true);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_code] ?? ($this->reason_code ? Str::headline($this->reason_code) : '—');
    }

    public function settlementLabel(): string
    {
        return self::SETTLEMENTS[$this->settlement] ?? Str::headline($this->settlement);
    }

    /**
     * How much of what came back went straight to a write-off.
     *
     * Worth surfacing: a supplier or a product with a lot of it has a real
     * problem behind it.
     */
    public function writtenOffValue(): float
    {
        return (float) $this->items
            ->reject(fn (SalesReturnItem $item) => $item->isResalable())
            ->sum(fn (SalesReturnItem $item) => (float) $item->quantity * (float) $item->unit_cost);
    }

    /** Mint the next shop-local reference: SR/MAIN/2026/00001. */
    public static function nextReference(Shop $shop): string
    {
        $prefix = sprintf('SR/%s/%s/', $shop->code, now()->format('Y'));

        $last = static::allShops()
            ->where('shop_id', $shop->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
