<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place stock sits inside an outlet.
 *
 * Every outlet has a main store, where goods are received, and a kitchen
 * store, where recipes are consumed from - see App\Support\Outlets::stores().
 * A bar or a cold room can be added as an "other" store.
 */
class Store extends Model
{
    use InOutlet;

    public const MAIN = 'main';

    public const KITCHEN = 'kitchen';

    public const OTHER = 'other';

    public const KINDS = [
        self::MAIN => 'Main store',
        self::KITCHEN => 'Kitchen',
        self::OTHER => 'Other',
    ];

    protected $fillable = ['tenant_id', 'outlet_id', 'name', 'code', 'kind', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
