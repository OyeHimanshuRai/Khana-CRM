<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One entry in the inventory ledger.
 *
 * Append-only: nothing updates or deletes these. A mistake is corrected by
 * another movement, so "why is there 3 kg less than yesterday" stays
 * answerable for as long as the shop keeps its books.
 */
class StockMovement extends Model
{
    use BelongsToShop;

    /* Why stock moved. */
    public const PURCHASE = 'purchase';

    public const SALE = 'sale';

    public const SALE_RETURN = 'sale_return';

    public const PURCHASE_RETURN = 'purchase_return';

    public const ADJUSTMENT = 'adjustment';

    public const TRANSFER_IN = 'transfer_in';

    public const TRANSFER_OUT = 'transfer_out';

    public const OPENING = 'opening';

    public const WASTAGE = 'wastage';

    /*
     | Ingredients a kitchen used to make a dish (§10).
     |
     | Its own type rather than a sale, because it is not one: nothing was
     | sold, a recipe was cooked. Keeping the two apart is what lets a
     | consumption report say "the kitchen used 14 kg of flour" without that
     | number being tangled up with what was billed.
     */
    public const CONSUMPTION = 'consumption';


    /** Human labels, and which way each one normally moves stock. */
    public const TYPES = [
        self::OPENING => ['label' => 'Opening Stock', 'in' => true],
        self::PURCHASE => ['label' => 'Purchase Receipt', 'in' => true],
        self::SALE => ['label' => 'Sale', 'in' => false],
        self::SALE_RETURN => ['label' => 'Sales Return', 'in' => true],
        self::PURCHASE_RETURN => ['label' => 'Purchase Return', 'in' => false],
        self::ADJUSTMENT => ['label' => 'Adjustment', 'in' => null],
        self::TRANSFER_IN => ['label' => 'Transfer In', 'in' => true],
        self::TRANSFER_OUT => ['label' => 'Transfer Out', 'in' => false],
        self::WASTAGE => ['label' => 'Wastage / Damage', 'in' => false],
        self::CONSUMPTION => ['label' => 'Kitchen Consumption', 'in' => false],
    ];

    protected $fillable = [
        'shop_id', 'warehouse_id', 'product_id', 'batch_id',
        'type', 'quantity', 'balance_after', 'unit_cost',
        'reference_type', 'reference_id', 'reason',
        'user_id', 'user_name',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** The invoice, receipt or adjustment that caused this. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where('type', $type);
    }

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
            ->when($to, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('reason', 'like', "%{$term}%")
            ->orWhere('user_name', 'like', "%{$term}%")
            ->orWhereHas('product', fn (Builder $p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")));
    }

    /* --------------------------------------------------------- accessors */

    public function label(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function isInward(): bool
    {
        return (float) $this->quantity > 0;
    }

    /** "+12.000" / "−3.500", already signed for display. */
    public function signedQuantity(): string
    {
        $quantity = (float) $this->quantity;

        return ($quantity > 0 ? '+' : '−').number_format(abs($quantity), 3);
    }
}
