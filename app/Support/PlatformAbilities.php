<?php

namespace App\Support;

use App\Models\User;

/**
 * What a company's staff can never do, whatever their role says.
 *
 * Most of the system is split by company: a company's invoices, stock and
 * customers are its own. A few things are not, because there is only one of
 * them for the whole platform - the public landing page and its content, the
 * company settings behind it, the email templates, the price list of plans,
 * the roles every company draws on. Letting a restaurant owner edit those
 * edits them for everybody.
 *
 * The roles seeded for a company leave these out, but a role is data: it can
 * be edited, copied from an old install, or granted by hand. This is the rule
 * that does not depend on any of that - a signed-in user who belongs to a
 * company (tenant_id is set) and is not a Super Admin is refused these
 * abilities outright. Platform staff, who have no company, are governed by
 * their roles as before.
 *
 * Enforced in User::hasPermissionTo(), which is what the permission
 * middleware, Gate::allows(), @allows and the sidebar all end up asking, so
 * a refused ability disappears from the menu as well as from the route.
 */
final class PlatformAbilities
{
    /**
     * Ability prefixes that belong to the platform alone.
     *
     * @var array<int, string>
     */
    private const PLATFORM = [
        // Every company's figures, side by side.
        'platform.',
        // The landing page's content, and the leads it collects.
        'content.',
        // Templates and the log of everything the platform has sent.
        'email.',
        // The company settings the public site is built from.
        'settings.general.',
        'settings.pages.',
        'settings.plans.',
        // Roles are defined once and shared by every company.
        'settings.roles.',
        'settings.health.',
        // One Firebase project for the whole platform.
        'settings.firebase.',
        // A company pays for its plan; it does not edit one.
        'settings.subscriptions.edit',
        'settings.subscriptions.export',
        'settings.tenants.create',
        'settings.tenants.delete',
    ];

    /**
     * Shared vocabulary a company may read but not change: the units and GST
     * slabs every company's products point at.
     *
     * @var array<int, string>
     */
    private const READ_ONLY = ['inventory.units.', 'finance.taxes.'];

    private const WRITES = ['create', 'edit', 'delete'];

    /** Is this ability one a company's staff may not hold? */
    public static function deniedFor(User $user, string $ability): bool
    {
        // Cheap string work first: this runs for every menu entry.
        if (! self::isPlatformOnly($ability)) {
            return false;
        }

        return $user->tenant_id !== null && ! $user->isSuperAdmin();
    }

    public static function isPlatformOnly(string $ability): bool
    {
        foreach (self::PLATFORM as $prefix) {
            if (str_starts_with($ability, $prefix)) {
                return true;
            }
        }

        foreach (self::READ_ONLY as $prefix) {
            if (str_starts_with($ability, $prefix) && in_array(substr($ability, strlen($prefix)), self::WRITES, true)) {
                return true;
            }
        }

        return false;
    }
}
