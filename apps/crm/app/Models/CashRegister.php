<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One shop's cash-register session for one business day.
 *
 * See the migration for why `expected_cash` is computed once, at close, and
 * stored rather than kept live.
 */
class CashRegister extends Model
{
    use BelongsToShop;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const APPROVED = 'approved';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::OPEN => ['label' => 'Open', 'tone' => 'warning'],
        self::CLOSED => ['label' => 'Closed', 'tone' => ''],
        self::APPROVED => ['label' => 'Approved', 'tone' => 'success'],
    ];

    protected $fillable = [
        'shop_id', 'business_date', 'status', 'opening_float', 'notes',
    ];

    /* expected_cash/counted_cash/variance and every by/at column are written by the service. */

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_float' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $d) => $q->whereDate('business_date', '>=', $d))
            ->when($to, fn (Builder $q, string $d) => $q->whereDate('business_date', '<=', $d));
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

    public function isApproved(): bool
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

    /** Positive: more cash than expected. Negative: less. Null: not closed yet. */
    public function isBalanced(): bool
    {
        return $this->variance !== null && abs((float) $this->variance) <= 0.004;
    }

    public function isOver(): bool
    {
        return $this->variance !== null && (float) $this->variance > 0.004;
    }

    public function isShort(): bool
    {
        return $this->variance !== null && (float) $this->variance < -0.004;
    }
}
