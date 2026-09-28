<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A few rupees taken out of the drawer for something that never went
 * through Payment - see the migration for why this is not the old Expense.
 *
 * Recorded, never edited, same as Payment: a mistake is a new row, not a
 * correction of this one, because CashRegisterService::expectedCash() has
 * already summed whatever was here by the time anyone would notice.
 */
class CashPaidOut extends Model
{
    use BelongsToShop, HasPublicId;

    protected $fillable = [
        'shop_id', 'amount', 'category', 'reason', 'paid_at', 'paid_by', 'paid_by_name',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
