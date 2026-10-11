<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of one kitchen order, as CRM reports it stage by stage.
 *
 * Its ingredients are booked once, when it reaches the stage the outlet
 * chose. The bill that later settles it books nothing more.
 */
class KitchenLine extends Model
{
    use InOutlet;

    /** The order a line climbs in. */
    public const STAGES = ['placed' => 0, 'accepted' => 1, 'preparing' => 2, 'ready' => 3, 'served' => 4];

    protected $attributes = ['cost_paise' => 0, 'wasted' => false, 'sales_item_id' => 0, 'sales_item_size_id' => 0];

    protected $fillable = [
        'tenant_id', 'outlet_id', 'ref', 'order_ref', 'sales_item_id', 'sales_item_size_id', 'item_ref',
        'variant_ref', 'option_refs', 'qty', 'stage', 'consumed_at', 'cost_paise', 'cancelled_at',
        'wasted', 'sale_line_id', 'business_date',
    ];

    protected function casts(): array
    {
        return [
            'option_refs' => 'array',
            'qty' => 'integer',
            'sales_item_id' => 'integer',
            'sales_item_size_id' => 'integer',
            'cost_paise' => 'integer',
            'consumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'wasted' => 'boolean',
            'business_date' => 'date',
        ];
    }

    public static function rank(?string $stage): int
    {
        return self::STAGES[$stage] ?? -1;
    }
}
