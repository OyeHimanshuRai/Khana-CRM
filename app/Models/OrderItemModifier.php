<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One add-on on one order line: "Extra cheese, +₹60".
 *
 * A row rather than a JSON column, because §13 asks for item-wise sales and
 * "how many extra cheeses did we sell in March" is exactly the question a
 * restaurant asks of its add-ons. That is a GROUP BY, not a JSON scan.
 *
 * The names are copied and the id is nullable, so an option deleted from the
 * menu next season does not take the record of what was eaten with it.
 */
class OrderItemModifier extends Model
{
    protected $fillable = [
        'order_item_id', 'modifier_option_id',
        'modifier_name', 'option_name', 'price',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * The option this was, while it still exists.
     *
     * Only for grouping in a report. Nothing printed on a bill reads through
     * this - see the class note.
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(ModifierOption::class, 'modifier_option_id');
    }

    /* --------------------------------------------------------- behaviour */

    /** "+ ₹60.00", or nothing at all when it was free. */
    public function priceLabel(): string
    {
        $price = (float) $this->price;

        if ($price === 0.0) {
            return '';
        }

        return ($price < 0 ? '− ₹' : '+ ₹').number_format(abs($price), 2);
    }
}
