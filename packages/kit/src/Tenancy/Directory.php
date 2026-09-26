<?php

namespace Khana\Kit\Tenancy;

/**
 * Which businesses and outlets exist, in the order a switcher lists them.
 *
 * CRM and ERP answer from their read-only copy of Accounts (see
 * ReferenceDirectory, the default binding); Accounts answers from its own
 * tables.
 */
interface Directory
{
    /**
     * @return list<int|string>
     */
    public function tenantKeys(): array;

    /**
     * The outlets of one business, or of every business for null.
     *
     * @return list<int|string>
     */
    public function outletKeys(int|string|null $tenantKey): array;

    /**
     * Display names for outlets, for the switcher. Keys it does not know are
     * left out.
     *
     * @param  list<int|string>  $outletKeys
     * @return array<int|string, string>
     */
    public function outletNames(array $outletKeys): array;
}
