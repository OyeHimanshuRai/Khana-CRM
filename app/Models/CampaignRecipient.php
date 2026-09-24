<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person a campaign was addressed to (SRS 15).
 *
 * Written before anything is sent, so "why did Mrs Mehta get this" has an
 * answer and a run interrupted half way can be resumed without sending twice.
 * See the migration.
 *
 * @property string $status
 */
class CampaignRecipient extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Never attempted - no number, or the campaign was cancelled. */
    public const SKIPPED = 'skipped';

    protected $fillable = [
        'campaign_id', 'customer_id', 'destination', 'name',
        'status', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::SENT => 'success',
            self::FAILED => 'danger',
            self::SKIPPED => 'muted',
            default => 'warning',
        };
    }
}
