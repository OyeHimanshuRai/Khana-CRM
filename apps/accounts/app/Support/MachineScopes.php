<?php

namespace App\Support;

/**
 * What a service-to-service token may be allowed to do, and which of it each
 * product's own client is given.
 *
 * A scope names one kind of read (or, for other billing software, one kind
 * of write) on one service's API. The products check them locally with
 * Accounts' public key, so a token is only ever as wide as the list here
 * lets its client ask for.
 *
 * Each product holds exactly its own reads of the other two services and
 * nothing else: CRM never needs CRM's own menu over HTTP, and neither
 * product reads the other's roles - only Accounts does, and it signs that
 * token itself (App\Services\OwnMachineToken).
 */
final class MachineScopes
{
    /** @var array<string, string> every scope a token may carry => what it opens */
    public const ALL = [
        'tenants:read' => 'Read businesses, outlets, people and entitlements',
        'roles:read' => 'Read the roles a product offers, a business\'s own included',
        'menu:read' => 'Read an outlet\'s menu from CRM',
        'sales:read' => 'Read sales and daily digests from CRM',
        'bills:read' => 'Read supplier bills from ERP',
        'stock:read' => 'Read stock, recipe cost, the day\'s food cost and the sold-out signals from ERP',
        'sales:write' => 'Record and cancel sales in ERP, for other billing software',
    ];

    /**
     * What each product's machine client may ask for.
     *
     *   CRM  the snapshot from Accounts; supplier bills, stock and cost
     *        from ERP for its screens and its nightly check.
     *   ERP  the snapshot from Accounts; the menu and the day's sales from
     *        CRM for its first sync and its nightly check.
     *
     * @var array<string, list<string>>
     */
    public const PRODUCT = [
        'crm' => ['tenants:read', 'bills:read', 'stock:read'],
        'erp' => ['tenants:read', 'menu:read', 'sales:read'],
    ];
}
