<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash that left the till in CRM, waiting to be filed as a supplier payment
 * or an expense.
 */
class PaidOut extends Model
{
    use InOutlet;

    public const PENDING = 'pending';

    public const FILED = 'filed';

    protected $fillable = [
        'ulid', 'tenant_id', 'outlet_id', 'business_date', 'amount_paise', 'category', 'note',
        'status', 'supplier_payment_id', 'expense_id',
    ];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'amount_paise' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }
}
