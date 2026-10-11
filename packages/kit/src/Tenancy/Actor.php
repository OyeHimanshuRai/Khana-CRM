<?php

namespace Khana\Kit\Tenancy;

/**
 * A member of staff, as the tenancy rules need to know them.
 *
 * Implemented by each app's own user model. Anything signed in that is not an
 * Actor - a storefront customer, say - is someone the scopes know nothing
 * about, and sees nothing.
 */
interface Actor
{
    /** Platform staff: may reach every business and sit in the consolidated view. */
    public function isPlatformAdmin(): bool;

    /** The business this person belongs to. Null for platform staff. */
    public function tenantKey(): int|string|null;

    /**
     * The outlets this person was given. Which ones they may reach right now
     * is these, narrowed to the business in context; ignored for platform
     * staff, who reach every outlet of that business.
     *
     * @return list<int|string>
     */
    public function assignedOutletKeys(): array;

    /** Where they land when they have never chosen, or lost what they chose. */
    public function defaultOutletKey(): int|string|null;

    public function rememberedTenantKey(): int|string|null;

    public function rememberedOutletKey(): int|string|null;

    /** Whether they chose the consolidated "All outlets" view. */
    public function prefersAllOutlets(): bool;

    /**
     * Remember a choice on the account, not the session, so a cashier does
     * not re-pick their till on every device.
     */
    public function rememberContext(int|string|null $tenantKey, int|string|null $outletKey, bool $allOutlets): void;
}
