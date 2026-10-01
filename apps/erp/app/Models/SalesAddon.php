<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;

/**
 * An add-on option - "extra cheese" - as ERP sees it. It can carry a recipe
 * of its own, which is added on top of the dish it was ordered with.
 */
class SalesAddon extends Model
{
    use InOutlet;

    protected $fillable = ['tenant_id', 'outlet_id', 'source', 'ref', 'group_name', 'name', 'price_paise', 'is_active'];

    protected function casts(): array
    {
        return ['price_paise' => 'integer', 'is_active' => 'boolean'];
    }
}
