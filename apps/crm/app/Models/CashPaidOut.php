<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    /** Stock bought: what a paid-out that names a supplier bill always is. */
    public const SUPPLIER_PAYMENT = 'supplier_payment';

    /**
     * What the cash was for, as the till offers it.
     *
     * The codes are the heads ERP files an expense under (its
     * Expense::CATEGORIES), with stock bought in front, so a paid-out
     * reaches ERP already saying where it belongs and nobody is asked a
     * second time.
     *
     * Written out here rather than read from ERP: an outlet with CRM alone
     * pays out too, and a Suite outlet has to be able to with ERP down.
     * The category column also still holds codes from before this list -
     * `petty_cash`, what a caller that names nothing gets - which is why
     * categoryLabel() does not assume the code is one of these.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        self::SUPPLIER_PAYMENT => 'Supplier (stock bought)',
        'gas' => 'Gas & fuel',
        'utilities' => 'Electricity & water',
        'rent' => 'Rent',
        'wages' => 'Wages & staff',
        'repairs' => 'Repairs & maintenance',
        'cleaning' => 'Cleaning & supplies',
        'transport' => 'Transport',
        'marketing' => 'Marketing',
        'other' => 'Other',
    ];

    protected $fillable = [
        'shop_id', 'amount', 'category', 'reason',
        'supplier_bill_ulid', 'supplier_name', 'supplier_bill_no',
        'paid_at', 'paid_by', 'paid_by_name',
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

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? Str::headline((string) $this->category);
    }

    /**
     * "Fresh Farms, bill FF/102" - the bill this cash paid, as it read on the
     * screen it was picked from. Null for cash that paid no bill.
     */
    public function billLabel(): ?string
    {
        if ($this->supplier_bill_ulid === null) {
            return null;
        }

        return self::describeBill($this->supplier_name, $this->supplier_bill_no);
    }

    /**
     * One wording for a bill, used for the list and for the reason recorded
     * when the cashier picked a bill and typed nothing else.
     */
    public static function describeBill(?string $supplier, ?string $number): string
    {
        $supplier = trim((string) $supplier);
        $number = trim((string) $number);

        return match (true) {
            $supplier !== '' && $number !== '' => "{$supplier}, bill {$number}",
            $supplier !== '' => $supplier,
            $number !== '' => "Bill {$number}",
            default => 'A supplier bill',
        };
    }
}
