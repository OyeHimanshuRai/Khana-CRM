<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step an order took (SRS 16).
 *
 * Append-only, written by an observer on Order so no caller has to remember
 * it. See the migration for why this exists beside ActivityLog rather than
 * inside it.
 *
 * @property int|null $seconds_in_previous
 */
class OrderStatusLog extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'order_id', 'from_status', 'to_status',
        'seconds_in_previous', 'changed_by', 'note',
    ];

    protected function casts(): array
    {
        return ['seconds_in_previous' => 'integer'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Steps that measure something.
     *
     * The first row of an order has no previous status to have sat in, so it
     * carries no duration - and averaging over it would drag every figure
     * towards zero.
     */
    public function scopeMeasured(Builder $query): Builder
    {
        return $query->whereNotNull('seconds_in_previous');
    }

    /* --------------------------------------------------------- behaviour */

    public function fromLabel(): string
    {
        return $this->from_status === null
            ? 'Placed'
            : (Order::STATUSES[$this->from_status] ?? ucfirst((string) $this->from_status));
    }

    public function toLabel(): string
    {
        return Order::STATUSES[$this->to_status] ?? ucfirst((string) $this->to_status);
    }

    /**
     * The wait, as a person would say it.
     *
     * Minutes and seconds under an hour, because a kitchen argues in both and
     * "4.2 minutes" is not a number anybody argues with.
     */
    public function waitLabel(): string
    {
        $seconds = $this->seconds_in_previous;

        if ($seconds === null) {
            return '—';
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m '.($seconds % 60).'s';
        }

        return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}
