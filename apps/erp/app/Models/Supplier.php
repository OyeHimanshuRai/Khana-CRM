<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Khana\Kit\Tenancy\BelongsToTenant;

/**
 * Somebody the business buys from. Shared by every outlet of the business;
 * the bills and payments belong to each outlet.
 */
class Supplier extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'contact_name', 'phone', 'email', 'gstin', 'address', 'terms_days', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'terms_days' => 'integer'];
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
