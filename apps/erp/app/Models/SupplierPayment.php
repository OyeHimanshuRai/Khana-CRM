<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money to a supplier, or a credit from one for goods sent back.
 */
class SupplierPayment extends Model
{
    use InOutlet;

    public const PAYMENT = 'payment';

    public const CREDIT = 'credit';

    public const METHODS = [
        'cash' => 'Cash',
        'bank' => 'Bank transfer',
        'upi' => 'UPI',
        'cheque' => 'Cheque',
        'drawer' => 'From the till',
    ];

    protected $fillable = [
        'tenant_id', 'outlet_id', 'supplier_id', 'supplier_bill_id', 'kind', 'amount_paise',
        'method', 'paid_on', 'reference', 'notes', 'user_id',
    ];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount_paise' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function methodLabel(): string
    {
        return $this->kind === self::CREDIT ? 'Goods returned' : (self::METHODS[$this->method] ?? $this->method);
    }
}
