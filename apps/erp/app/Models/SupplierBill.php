<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Khana\Kit\Support\Ulid;

/**
 * What is owed to a supplier for one delivery, and how much of it has been
 * paid. `paid_paise` is kept by App\Services\Purchasing, never set by hand.
 *
 * Carries a ULID because CRM names it: the paid-out screen lists open bills,
 * and the paid-out event says which one the cash was for.
 */
class SupplierBill extends Model
{
    use InOutlet;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $attributes = ['status' => self::OPEN, 'paid_paise' => 0];

    protected $fillable = [
        'ulid', 'tenant_id', 'outlet_id', 'supplier_id', 'bill_no', 'billed_on', 'due_on',
        'total_paise', 'paid_paise', 'status', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return ['billed_on' => 'date', 'due_on' => 'date', 'total_paise' => 'integer', 'paid_paise' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (SupplierBill $bill) {
            $bill->ulid ??= Ulid::generate();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(GoodsReceipt::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function duePaise(): int
    {
        return max(0, $this->total_paise - $this->paid_paise);
    }

    public function isOverdue(?string $today = null): bool
    {
        return $this->status === self::OPEN
            && $this->due_on !== null
            && $this->due_on->toDateString() < ($today ?? now()->toDateString());
    }
}
