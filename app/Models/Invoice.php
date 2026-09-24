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
 * A sales invoice: the POS receipt and the manual invoice, same document.
 *
 * Every figure on it was copied at billing time - see the migration. The
 * model therefore never recalculates anything from the current catalogue;
 * InvoiceService does the arithmetic once, on the way in.
 */
class Invoice extends Model
{
    use BelongsToShop;

    /* Where the sale came from. */
    public const POS = 'pos';

    public const MANUAL = 'manual';

    public const ONLINE = 'online';

    /* Lifecycle. */
    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const RETURNED = 'returned';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::DRAFT => ['label' => 'Draft', 'tone' => ''],
        self::ISSUED => ['label' => 'Unpaid', 'tone' => 'warning'],
        self::PARTIAL => ['label' => 'Part paid', 'tone' => 'warning'],
        self::PAID => ['label' => 'Paid', 'tone' => 'success'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'danger'],
        self::RETURNED => ['label' => 'Returned', 'tone' => 'danger'],
    ];

    /** @var array<string, string> */
    public const CHANNELS = [
        self::POS => 'Counter',
        self::MANUAL => 'Manual',
        self::ONLINE => 'Online',
    ];

    protected $fillable = [
        'shop_id', 'warehouse_id', 'customer_id', 'table_session_id',
        'customer_name', 'customer_mobile', 'customer_gstin', 'customer_state', 'billing_address',
        'number', 'channel', 'status', 'invoiced_at',
        'is_inter_state', 'place_of_supply',
        'is_credit', 'due_date', 'notes',
    ];

    /*
     | Every money column is written by InvoiceService, never mass-assigned.
     | A total that a form could set is a total nobody can trust.
     */

    protected function casts(): array
    {
        return [
            'invoiced_at' => 'datetime',
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
            'is_inter_state' => 'boolean',
            'is_credit' => 'boolean',
            'subtotal' => 'decimal:2',
            'line_discount_total' => 'decimal:2',
            'invoice_discount' => 'decimal:2',
            'invoice_discount_percent' => 'decimal:3',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'cess_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'due_total' => 'decimal:2',
            'cost_total' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The sitting this bill came off, for a dine-in one (§6).
     *
     * On the invoice rather than on the session, because a split bill is
     * several invoices off one table - see the migration.
     */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'reference')->orderBy('paid_at');
    }

    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(CustomerLedger::class, 'reference');
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('number', 'like', "%{$term}%")
            ->orWhere('customer_name', 'like', "%{$term}%")
            ->orWhere('customer_mobile', 'like', "%{$term}%")
            ->orWhere('created_by_name', 'like', "%{$term}%"));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeOfChannel(Builder $query, ?string $channel): Builder
    {
        return blank($channel) ? $query : $query->where('channel', $channel);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('invoiced_at', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('invoiced_at', '<=', $d));
    }

    /**
     * Invoices that count as sales.
     *
     * Cancelled ones keep their number and their history but must never
     * appear in a revenue figure - forgetting this is how a sales report
     * comes out higher than the bank.
     */
    public function scopeCounted(Builder $query): Builder
    {
        /*
         | Qualified, because reports join this table to others that also have
         | a `status` - table_sessions, orders - and an unqualified column
         | there is not a wrong answer but a fatal one. Costing nothing when
         | there is no join, it is the version that cannot break later.
         */
        return $query->whereNotIn($query->qualifyColumn('status'), [self::DRAFT, self::CANCELLED]);
    }

    /** Anything still owed. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->counted()->where('due_total', '>', 0);
    }

    /** Past its due date and still owed. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today());
    }

    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', today())
            ->whereDate('due_date', '<=', today()->addDays($days));
    }

    /* --------------------------------------------------------- behaviour */

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isSettled(): bool
    {
        return (float) $this->due_total <= 0.004;
    }

    public function isOverdue(): bool
    {
        return ! $this->isSettled()
            && ! $this->isCancelled()
            && $this->due_date !== null
            && $this->due_date->isBefore(today());
    }

    /** Days past due; negative while still in date. */
    public function daysOverdue(): ?int
    {
        return $this->due_date === null
            ? null
            : (int) $this->due_date->diffInDays(today(), false);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? Str::headline($this->channel);
    }

    /** Who to print on the bill when there is no customer record. */
    public function billedTo(): string
    {
        return $this->customer_name ?: 'Cash Sale';
    }

    /**
     * Estimated gross profit on this invoice.
     *
     * Estimated, and named so: it uses the weighted average cost captured at
     * sale time, which is a defensible basis but not the only one a business
     * might choose.
     */
    public function grossProfit(): float
    {
        return (float) $this->subtotal - (float) $this->cost_total;
    }

    /**
     * The status a given paid amount implies.
     *
     * Kept here rather than in the service so the invoice screen and the
     * payment screen cannot disagree about what "part paid" means.
     */
    public function statusForPaid(float $paid): string
    {
        if ($this->isCancelled()) {
            return self::CANCELLED;
        }

        $total = (float) $this->grand_total;

        return match (true) {
            $paid >= $total - 0.004 => self::PAID,
            $paid > 0.004 => self::PARTIAL,
            default => self::ISSUED,
        };
    }
}
