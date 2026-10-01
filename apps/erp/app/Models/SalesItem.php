<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a guest orders, as ERP sees it.
 *
 * Read-only here: it is a copy of the menu in CRM (kept current by event),
 * or it was made from a CSV import or the sales API for an outlet that bills
 * with something else. What ERP adds is the recipe.
 */
class SalesItem extends Model
{
    use InOutlet;

    public const CRM = 'crm';

    public const CSV = 'csv';

    public const API = 'api';

    public const SOURCES = [
        self::CRM => 'Khana CRM',
        self::CSV => 'Imported file',
        self::API => 'Other billing software',
    ];

    protected $fillable = [
        'tenant_id', 'outlet_id', 'source', 'ref', 'name', 'category', 'sku', 'barcode',
        'price_paise', 'is_active', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['price_paise' => 'integer', 'is_active' => 'boolean', 'archived_at' => 'datetime'];
    }

    public function sizes(): HasMany
    {
        return $this->hasMany(SalesItemSize::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }
}
