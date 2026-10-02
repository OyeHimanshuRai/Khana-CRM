<?php

namespace App\Events\Outbound;

use App\Models\CashPaidOut;
use App\Models\Shop;

/**
 * Cash out of the drawer, for crm.cash.paid_out.
 *
 * supplier_bill_id is the bill the cashier picked on the register's paid-out
 * card, which lists ERP's unpaid bills for an outlet that has ERP. ERP turns
 * a paid-out that names one into a payment against that bill. One that names
 * none - most of them, and every one from a caller that only knows an amount
 * and a reason - reaches ERP as cash to be filed under its category, which is
 * what the contract says happens when no bill is named.
 *
 * The id is sent as it was stored and never looked up: the bill is ERP's
 * row, and ERP is the one that knows whether it is still owed.
 */
final class PaidOutPayload
{
    public const DEFAULT_CATEGORY = 'petty_cash';

    /**
     * @return array<string, mixed>
     */
    public function for(CashPaidOut $paidOut): array
    {
        $shop = Shop::withTrashed()->findOrFail($paidOut->shop_id);
        $category = (string) $paidOut->category;

        return [
            'paid_out_id' => PublicIds::of($paidOut),
            'business_date' => OutletClock::dateOf($shop, $paidOut->paid_at ?? $paidOut->created_at ?? now()),
            'amount_paise' => Wire::paise($paidOut->amount),
            'category' => preg_match('/^[a-z][a-z0-9_]{0,39}$/', $category) === 1 ? $category : self::DEFAULT_CATEGORY,
            'supplier_bill_id' => Wire::ulid($paidOut->supplier_bill_ulid),
            'note' => Wire::text($paidOut->reason, 1000),
        ];
    }
}
