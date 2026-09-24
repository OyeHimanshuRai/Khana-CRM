<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Money the shop spends that is not buying stock.
 *
 * Approving is what turns it into money out - an expense somebody typed in
 * is a claim until it is agreed.
 */
class Expense extends Model
{
    use BelongsToShop;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::DRAFT => ['label' => 'Awaiting approval', 'tone' => 'warning'],
        self::APPROVED => ['label' => 'Approved', 'tone' => 'success'],
        self::REJECTED => ['label' => 'Rejected', 'tone' => 'danger'],
    ];

    protected $fillable = [
        'shop_id', 'expense_category_id', 'reference', 'spent_on',
        'title', 'notes', 'paid_to', 'amount', 'method', 'transaction_ref',
        'status', 'review_note',
    ];

    /* attachment_path is set only by the controller's upload handler. */

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
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
            ->orWhere('title', 'like', "%{$term}%")
            ->orWhere('paid_to', 'like', "%{$term}%")
            ->orWhere('transaction_ref', 'like', "%{$term}%")
            ->orWhere('created_by_name', 'like', "%{$term}%"));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('spent_on', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('spent_on', '<=', $d));
    }

    /** Expenses that count as money spent. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /* --------------------------------------------------------- behaviour */

    public function isEditable(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    public function methodLabel(): string
    {
        return Payment::METHODS[$this->method]['label'] ?? Str::headline($this->method);
    }

    /** The receipt photograph, if there is one on disk. */
    public function attachmentUrl(): ?string
    {
        if (blank($this->attachment_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->attachment_path) ? $disk->url($this->attachment_path) : null;
    }

    /** Mint the next shop-local reference: EXP/MAIN/2026/00001. */
    public static function nextReference(Shop $shop): string
    {
        $prefix = sprintf('EXP/%s/%s/', $shop->code, now()->format('Y'));

        $last = static::allShops()
            ->where('shop_id', $shop->id)
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('reference');

        $number = $last ? ((int) Str::afterLast($last, '/')) + 1 : 1;

        return $prefix.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
