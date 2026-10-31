<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One attempt to print something (SRS 6, 8.5).
 *
 * Append-only. A failed attempt stays failed; a retry is a new row. See the
 * migration for why the failures are the valuable part.
 *
 * @property bool $is_reprint
 * @property string $status
 */
class PrintJob extends Model
{
    use BelongsToShop;

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'shop_id', 'printer_id', 'printable_type', 'printable_id',
        'kind', 'kitchen_station_id', 'status', 'copies',
        'is_reprint', 'reason', 'error', 'bytes', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'copies' => 'integer',
            'bytes' => 'integer',
            'is_reprint' => 'boolean',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return MorphTo<Model, $this> */
    public function printable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Printer, $this> */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    /** @return BelongsTo<KitchenStation, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeReprints(Builder $query): Builder
    {
        return $query->where('is_reprint', true);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::FAILED);
    }

    /**
     * Earlier attempts at the same document, for deciding "is this a reprint".
     */
    public function scopeForSame(Builder $query, Model $printable, string $kind): Builder
    {
        return $query
            ->where('printable_type', $printable->getMorphClass())
            ->where('printable_id', $printable->getKey())
            ->where('kind', $kind);
    }

    /* --------------------------------------------------------- behaviour */

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::SENT => 'Sent',
            self::FAILED => 'Failed',
            default => 'Queued',
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::SENT => 'success',
            self::FAILED => 'danger',
            default => 'warning',
        };
    }

    /** What was printed, in a form a person can read in a log. */
    public function subjectLabel(): string
    {
        $printable = $this->printable;

        if ($printable === null) {
            return '—';
        }

        return $printable->getAttribute('order_number')
            ?? $printable->getAttribute('invoice_number')
            ?? class_basename($printable).' #'.$printable->getKey();
    }
}
