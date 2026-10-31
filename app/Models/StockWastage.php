<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One thing a kitchen threw away (§10).
 *
 * A log rather than a document - see the migration for why it is not a stock
 * adjustment.
 */
class StockWastage extends Model
{
    use BelongsToShop;

    /**
     * Why it was thrown away.
     *
     * Short and specific, because a list that offers "Other" first is a list
     * where everything is Other and the report says nothing. Each of these is
     * a different conversation with a different person:
     *
     *   spoiled    the store is over-ordering, or the fridge is failing
     *   burnt      the line is rushing, or a recipe needs its timing revised
     *   dropped    genuinely an accident; the number to watch is the trend
     *   expired    the store is over-ordering, or nobody is rotating
     *   returned   the dish is wrong, not the kitchen
     *   staff      not waste at all, but it is stock that left unsold and a
     *              food cost that ignored it would be understated
     *   sample     a taster, a photo, a supplier visit
     *
     * @var array<string, string>
     */
    public const REASONS = [
        'spoiled' => 'Spoiled',
        'burnt' => 'Burnt or overcooked',
        'dropped' => 'Dropped or spilled',
        'expired' => 'Past its date',
        'returned' => 'Sent back by a guest',
        'staff' => 'Staff meal',
        'sample' => 'Sample or tasting',
        'other' => 'Other',
    ];

    protected $fillable = [
        'shop_id', 'warehouse_id', 'product_id', 'quantity',
        'reason_code', 'note', 'unit_cost', 'cost_value',
        'recorded_by', 'recorded_by_name', 'wasted_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'cost_value' => 'decimal:2',
            'wasted_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeOfReason(Builder $query, ?string $reason): Builder
    {
        return blank($reason) ? $query : $query->where('reason_code', $reason);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('wasted_at', [$from, $to]);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('note', 'like', "%{$term}%")
            ->orWhere('recorded_by_name', 'like', "%{$term}%")
            ->orWhereHas('product', fn (Builder $p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")));
    }

    /* --------------------------------------------------------- behaviour */

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_code] ?? Str::headline((string) $this->reason_code);
    }

    /** "2.5 KG" - as somebody in a kitchen would read it. */
    public function label(): string
    {
        $quantity = rtrim(rtrim(number_format((float) $this->quantity, 4, '.', ''), '0'), '.');

        return $quantity.' '.($this->product?->unit?->code ?? '');
    }

    /**
     * Whether this row is really waste, or stock that simply left unsold.
     *
     * A staff meal and a tasting are not a kitchen's failure, and a report
     * that added them into "waste" would have a manager chasing a number that
     * is working as intended. They are still deducted, because the food is
     * gone either way.
     */
    public function isLoss(): bool
    {
        return ! in_array($this->reason_code, ['staff', 'sample'], true);
    }
}
