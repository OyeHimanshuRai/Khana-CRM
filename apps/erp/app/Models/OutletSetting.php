<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How stock behaves at one outlet. A missing row means the defaults - see
 * App\Support\Outlets::settings().
 */
class OutletSetting extends Model
{
    public const STAGES = [
        'accepted' => 'When the kitchen accepts the order',
        'ready' => 'When the dish is ready',
        'served' => 'When the dish is served',
    ];

    protected $primaryKey = 'outlet_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['outlet_id', 'tenant_id', 'consume_stage', 'exclude_expired', 'input_tax_is_cost', 'kitchen_only', 'csv_layout'];

    protected function casts(): array
    {
        return ['exclude_expired' => 'boolean', 'input_tax_is_cost' => 'boolean', 'kitchen_only' => 'boolean', 'csv_layout' => 'array'];
    }
}
