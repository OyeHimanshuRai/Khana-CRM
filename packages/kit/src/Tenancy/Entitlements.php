<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Which products an outlet has bought, from the local copy of Accounts.
 *
 * An outlet may own CRM, ERP, or both - the Suite. Each product is complete on
 * its own, so these answers decide what a product shows and what it sends,
 * never whether the other product is up:
 *
 *   allows()  may use it fully: trial, active, or the grace days after a
 *             missed payment
 *   owns()    still holds it, including suspended (read and export only) -
 *             so CRM keeps telling a suspended ERP about sales, and a renewal
 *             does not start from a hole
 *   isSuite() owns both, which is what switches the link between them on.
 *             There is no setting for it.
 *
 * Nothing here trusts a request. The answers come from accounts.entitlement
 * events, and an outlet nobody has heard of has no products at all.
 */
final class Entitlements
{
    public const PRODUCTS = ['crm', 'erp'];

    /** States in which the product can be used normally. */
    public const USABLE = ['trial', 'active', 'grace'];

    /** States in which the outlet still holds the product. */
    public const HELD = ['trial', 'active', 'grace', 'suspended'];

    /** @var array<string, array<string, string>> outlet => product => state */
    private array $states = [];

    public function state(int|string $outletId, string $product): ?string
    {
        return $this->statesFor($outletId)[$product] ?? null;
    }

    public function allows(int|string $outletId, string $product): bool
    {
        return in_array($this->state($outletId, $product), self::USABLE, true);
    }

    public function owns(int|string $outletId, string $product): bool
    {
        return in_array($this->state($outletId, $product), self::HELD, true);
    }

    public function isReadOnly(int|string $outletId, string $product): bool
    {
        return $this->state($outletId, $product) === 'suspended';
    }

    public function isSuite(int|string $outletId): bool
    {
        return $this->owns($outletId, 'crm') && $this->owns($outletId, 'erp');
    }

    /**
     * The products this outlet can use right now.
     *
     * @return list<string>
     */
    public function products(int|string $outletId): array
    {
        return array_values(array_filter(self::PRODUCTS, fn (string $product) => $this->allows($outletId, $product)));
    }

    public function forget(): void
    {
        $this->states = [];
    }

    /**
     * @return array<string, string>
     */
    private function statesFor(int|string $outletId): array
    {
        $key = (string) $outletId;

        return $this->states[$key] ??= DB::table('khana_entitlements')
            ->where('outlet_id', $key)
            ->pluck('state', 'product')
            ->all();
    }
}
