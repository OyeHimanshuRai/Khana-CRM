<?php

namespace App\Events\Outbound;

use App\Models\CashPaidOut;
use App\Models\Shop;

/**
 * Cash out of the drawer, for crm.cash.paid_out.
 *
 * supplier_bill_id is always null for now. The bill a paid-out settles is
 * ERP's (purchasing moved there), and nothing in CRM yet lets a cashier pick
 * one - so every paid-out reaches ERP as an expense under its category,
 * which is what the contract says happens when no bill is named.
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
            'supplier_bill_id' => null,
            'note' => Wire::text($paidOut->reason, 1000),
        ];
    }
}
