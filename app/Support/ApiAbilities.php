<?php

namespace App\Support;

/**
 * What an API token may be granted, and the staff right behind each ability.
 *
 * A token acts for the person who minted it, so it can never do more than
 * that person may do today: each ability names the permission the same act
 * needs in the admin app. Checked when a token is minted (nobody hands out a
 * right they do not hold) and again on every call (a right taken away later
 * stops the token too) - see EnsureTokenOwnerMayAct.
 *
 * Because the check goes through the permission, the branch's modules apply
 * to the API exactly as they do to the screens.
 */
final class ApiAbilities
{
    /** ability => what the token screen calls it */
    public const LABELS = [
        'menu:read' => 'Read the menu',
        'menu:write' => 'Mark dishes sold out',
        'tables:read' => 'Read the floor and its tables',
        'orders:read' => 'Read orders and their status',
        'orders:write' => 'Create orders and move them along',
    ];

    /** ability => the permission it stands for */
    public const PERMISSIONS = [
        'menu:read' => 'inventory.products.view',
        'menu:write' => 'inventory.products.edit',
        'tables:read' => 'dining.tables.view',
        'orders:read' => 'sales.orders.view',
        'orders:write' => 'sales.orders.create',
    ];

    public static function permissionFor(string $ability): ?string
    {
        return self::PERMISSIONS[$ability] ?? null;
    }
}
