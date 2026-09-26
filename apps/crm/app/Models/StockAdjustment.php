<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A stock correction awaiting, or having had, a decision.
 *
 * Nothing here moves stock on its own. StockAdjustmentService applies an
 * approved document through StockService, and the movements it writes point
 * back at this row - so the ledger can always answer "which document?".
 */
class StockAdjustment extends Model
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
        self::APPROVED => ['label' => 'Applied', 'tone' => 'success'],
        self::REJECTED => ['label' => 'Rejected', 'tone' => 'danger'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
    ];

    /** Coarse categories, so the reasons can be reported on. */
    public const REASONS = [
        'count' => 'Physical count',
        'damage' => 'Damaged',
        'expiry' => 'Expired / written off',
        'theft' => 'Loss or theft',
        'opening' => 'Opening stock',
        'correction' => 'Data correction',
        'other' => 'Other',
    ];

    protected $fillable = [
        'shop_id', 'warehouse_id', 'reference', 'adjustment_date',
        'status', 'reason_code', 'reason', 'review_note',
    ];

    /*
     | The totals and every by/at column are written by the service when the
     | document is applied, never from a form.
     */

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'approved_at' => 'datetime',
            'total_in' => 'decimal:3',
            'total_out' => 'decimal:3',
            'value_change' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('reference', 'like', "%{$term}%")
            ->orWhere('reason', 'like', "%{$term}%")
            ->orWhere('created_by_name', 'like', "%{$term}%"));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('adjustment_date', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('adjustment_date', '<=', $d));
    }

    /* --------------------------------------------------------- behaviour */

    /** Still editable? Only before a decision has been taken. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING], true);
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::APPROVED, self::REJECTED, self::CANCELLED], true);
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

    /**
     * Mint the next shop-local reference: ADJ/MAIN/2026/00001.
     *
     * Sequential per shop so the numbers stay short and gaps are meaningful.
     */
    public static function nextReference(Shop $shop): string
    {
        $prefix = sprintf('ADJ/%s/%s/', $shop->code, now()->format('Y'));

        $last = static::allShops()
            ->where('shop_id', $shop->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
