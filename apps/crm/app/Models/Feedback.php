<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the guest thought (SRS 15).
 *
 * @property int $rating
 */
class Feedback extends Model
{
    use BelongsToShop;

    /** Laravel would guess "feedbacks", which is not a word. */
    protected $table = 'feedback';

    /**
     * At or below this, somebody should look at it today.
     *
     * Three is not a complaint, but it is not a return visit either - and a
     * restaurant that only reads its one-stars never finds out why the
     * threes stopped coming back.
     */
    public const POOR = 3;

    protected $fillable = [
        'shop_id', 'table_session_id', 'order_id', 'customer_id',
        'rating', 'food_rating', 'service_rating', 'comment',
        'guest_name', 'guest_mobile',
        'response', 'responded_at', 'responded_by',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'food_rating' => 'integer',
            'service_rating' => 'integer',
            'responded_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<TableSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopePoor(Builder $query): Builder
    {
        return $query->where('rating', '<=', self::POOR);
    }

    /** Unhappy and unanswered - the only list that matters today. */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->poor()->whereNull('responded_at');
    }

    /* --------------------------------------------------------- behaviour */

    public function isAnswered(): bool
    {
        return $this->responded_at !== null;
    }

    public function tone(): string
    {
        return match (true) {
            $this->rating >= 4 => 'success',
            $this->rating === 3 => 'warning',
            default => 'danger',
        };
    }

    /** Who left it, or the honest absence of a name. */
    public function fromLabel(): string
    {
        return $this->customer?->name ?: ($this->guest_name ?: 'Anonymous');
    }
}
