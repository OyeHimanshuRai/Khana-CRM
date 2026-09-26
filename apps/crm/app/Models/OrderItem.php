<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product line on an order, priced and named as it was at checkout.
 *
 * `reserved_batches` is the exact FEFO allocation StockService::reserve()
 * was given at placement time - [{batch_id, warehouse_id, quantity}, ...] -
 * kept so OrderService can release precisely that reservation later without
 * re-deriving it. A fresh pick at fulfilment time could legitimately choose
 * a different lot than the one actually held.
 */
class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'product_variant_id',
        'product_name', 'variant_name', 'sku', 'note',
        'quantity', 'settled_quantity', 'unit_price', 'line_total', 'reserved_batches',
        'kitchen_station_id', 'kitchen_status',
        'kitchen_started_at', 'kitchen_ready_at', 'recipe_consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'settled_quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:2',
            'reserved_batches' => 'array',
            'kitchen_started_at' => 'datetime',
            'kitchen_ready_at' => 'datetime',
            'recipe_consumed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** The size ordered, while that row still exists. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** The add-ons on this line, in the order they were chosen. */
    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class)->orderBy('id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /* ------------------------------------------------------- the kitchen */

    /**
     * Where this line is being cooked, while that station still exists.
     *
     * A snapshot taken at placement, not a live lookup - see the migration.
     */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    /**
     * Lines a station still has work to do on.
     *
     * `served` is excluded as well as absent: once a plate has gone out the
     * line is history, and a KDS that keeps showing it is a KDS nobody can
     * read by nine o'clock.
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('kitchen_status', [
            Order::PENDING, Order::CONFIRMED, Order::PREPARING, Order::READY,
        ]);
    }

    public function scopeAtStation(Builder $query, ?int $stationId): Builder
    {
        return $stationId ? $query->where('kitchen_station_id', $stationId) : $query;
    }

    /** Whether this line is the kitchen's problem at all. See the migration. */
    public function isKitchenLine(): bool
    {
        return $this->kitchen_status !== null;
    }

    /**
     * The next rung, or null at the top.
     *
     * Deliberately the *order's* ladder. A line and the ticket it sits on
     * climb the same five rungs, which is what lets the ticket's status be
     * the least-advanced of its lines rather than a second thing to keep in
     * step - see KitchenService::rollUp().
     */
    public function nextKitchenStatus(): ?string
    {
        $at = array_search($this->kitchen_status, Order::KITCHEN_FLOW, true);

        return $at === false ? null : (Order::KITCHEN_FLOW[$at + 1] ?? null);
    }

    public function kitchenStatusLabel(): string
    {
        return Order::STATUSES[$this->kitchen_status] ?? '—';
    }

    /* ------------------------------------------------------------ billing */

    /**
     * How much of this line nobody has paid for yet (§6).
     *
     * A quantity rather than a flag, because "three of the five beers are
     * mine" is a real split and a boolean cannot say it.
     */
    public function unsettledQuantity(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->settled_quantity, 3));
    }

    public function isSettled(): bool
    {
        return $this->unsettledQuantity() <= 0.0005;
    }

    /** How this line reads on a slip: "Margherita (7\") x2". */
    public function title(): string
    {
        $title = $this->product_name;

        if (filled($this->variant_name)) {
            $title .= ' ('.$this->variant_name.')';
        }

        return $title;
    }
}
