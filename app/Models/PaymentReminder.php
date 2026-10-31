<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One reminder: planned, then sent.
 *
 * The same row at two moments rather than two tables, so "did this customer
 * actually get told" is one lookup rather than a reconciliation.
 */
class PaymentReminder extends Model
{
    use BelongsToShop;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::PENDING => ['label' => 'Queued', 'tone' => 'warning'],
        self::SENT => ['label' => 'Sent', 'tone' => 'success'],
        self::FAILED => ['label' => 'Failed', 'tone' => 'danger'],
        self::SKIPPED => ['label' => 'Not needed', 'tone' => ''],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => ''],
    ];

    protected $fillable = [
        'shop_id', 'customer_id', 'invoice_id',
        'trigger', 'channel', 'status',
        'scheduled_for', 'sent_at',
        'recipient', 'subject', 'body',
        'amount_due', 'due_date',
        'attempts', 'last_error', 'skip_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'due_date' => 'date',
            'amount_due' => 'decimal:2',
            'attempts' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Reminders that are due to go out now.
     *
     * Anything scheduled for the past counts: a run that was missed should
     * catch up rather than silently drop the day's reminders.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::PENDING)
            ->where('scheduled_for', '<=', now())
            ->where('attempts', '<', config('reminders.max_attempts', 3));
    }

    public function scopeOfTrigger(Builder $query, ?string $trigger): Builder
    {
        return blank($trigger) ? $query : $query->where('trigger', $trigger);
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('recipient', 'like', "%{$term}%")
            ->orWhere('subject', 'like', "%{$term}%")
            ->orWhereHas('customer', fn (Builder $c) => $c
                ->where('name', 'like', "%{$term}%")
                ->orWhere('mobile', 'like', "%{$term}%")));
    }

    /* --------------------------------------------------------- behaviour */

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? Str::headline($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? '';
    }

    public function triggerLabel(): string
    {
        return config("reminders.triggers.{$this->trigger}.label")
            ?? Str::headline($this->trigger);
    }

    public function channelLabel(): string
    {
        return config("reminders.channels.{$this->channel}.label")
            ?? Str::headline($this->channel);
    }

    /** Whether this one may still be retried. */
    public function isRetryable(): bool
    {
        return $this->status === self::FAILED
            && $this->attempts < (int) config('reminders.max_attempts', 3);
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => self::SENT,
            'sent_at' => now(),
            'attempts' => $this->attempts + 1,
            'last_error' => null,
        ])->save();
    }

    /**
     * Record a delivery failure.
     *
     * Stays pending while there are attempts left, so the next run picks it
     * up; only the last failure is final.
     */
    public function markFailed(string $error): void
    {
        $attempts = $this->attempts + 1;
        $exhausted = $attempts >= (int) config('reminders.max_attempts', 3);

        $this->forceFill([
            'status' => $exhausted ? self::FAILED : self::PENDING,
            'attempts' => $attempts,
            'last_error' => Str::limit($error, 1000, ''),
        ])->save();
    }

    /**
     * Not sent, and rightly so - the invoice was paid, or there was nowhere
     * to send it. Distinct from a failure, which needs somebody to look.
     */
    public function markSkipped(string $reason): void
    {
        $this->forceFill([
            'status' => self::SKIPPED,
            'skip_reason' => $reason,
        ])->save();
    }
}
