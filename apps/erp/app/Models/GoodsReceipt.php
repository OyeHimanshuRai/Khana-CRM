<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery taken in at the back door: what arrived, into which store, at
 * what cost. Written by App\Services\Purchasing, which books the stock and
 * raises the supplier bill in the same breath.
 */
class GoodsReceipt extends Model
{
    use InOutlet;

    protected $fillable = [
        'tenant_id', 'outlet_id', 'store_id', 'supplier_id', 'purchase_order_id', 'supplier_bill_id',
        'number', 'received_on', 'bill_no', 'net_paise', 'tax_paise', 'total_paise', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'net_paise' => 'integer', 'tax_paise' => 'integer', 'total_paise' => 'integer'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
