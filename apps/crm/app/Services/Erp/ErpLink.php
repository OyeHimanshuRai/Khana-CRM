<?php

namespace App\Services\Erp;

use App\Models\Shop;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Entitlements;

/**
 * Is this outlet linked to ERP?
 *
 * The link has no setting. An outlet that owns both products is a Suite
 * outlet, and what it owns is Accounts' answer, kept by the kit - so the link
 * switches on by itself the day ERP is bought and off the day it ends.
 *
 * Two answers, because the screens need to tell three outlets apart:
 *
 *   linked()   holds ERP, and this install knows where ERP is. The panels
 *              that read ERP are drawn, and nothing else ever calls it.
 *
 *   owns()     holds ERP, whether or not it can be reached from here. An
 *              outlet that does not is CRM alone: no stock, no recipes and
 *              no purchasing anywhere, and where the Suite would show them a
 *              small card says what ERP adds.
 *
 * "Holds" includes a suspended subscription, the same rule the outbox and
 * ERP's own API follow (Entitlements::owns): ERP still answers reads for it,
 * and a renewal should not find its panels gone.
 *
 * An outlet in between - it owns ERP, but this install was never told ERP's
 * address - is neither linked nor alone, and is shown nothing about ERP at
 * all.
 */
final class ErpLink
{
    /** ERP, in Accounts' words. */
    public const PRODUCT = 'erp';

    public function __construct(private readonly Entitlements $entitlements) {}

    public function linked(?Shop $shop): bool
    {
        return $shop !== null && $this->linkedOutlet($shop->ulid);
    }

    public function owns(?Shop $shop): bool
    {
        return $shop !== null && $this->ownsOutlet($shop->ulid);
    }

    /**
     * The same question, for a caller that holds Accounts' outlet id rather
     * than the shop - the client, and the nightly check.
     */
    public function linkedOutlet(?string $outletUlid): bool
    {
        return self::url() !== null && $this->ownsOutlet($outletUlid);
    }

    /**
     * Where ERP lives, without a trailing slash - or null on an install that
     * does not run it (KHANA_ERP_URL).
     */
    public static function url(): ?string
    {
        $base = config('khana.urls.erp');

        return is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : null;
    }

    /**
     * A shop Accounts has never named has no id to ask about, and cannot
     * have been sold anything.
     */
    private function ownsOutlet(?string $outletUlid): bool
    {
        return Ulid::isValid($outletUlid) && $this->entitlements->owns($outletUlid, self::PRODUCT);
    }
}
