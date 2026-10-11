<?php

namespace Khana\Kit\Tenancy;

/**
 * Shorthand for the outlet half of the request's Context.
 */
final class CurrentOutlet
{
    public static function id(): int|string|null
    {
        return self::context()->outletId();
    }

    /**
     * @return list<int|string>
     */
    public static function accessibleIds(): array
    {
        return self::context()->accessibleOutlets();
    }

    public static function canAccess(int|string $outletId): bool
    {
        return self::context()->canAccessOutlet($outletId);
    }

    public static function canSeeAll(): bool
    {
        return self::context()->canSeeAllOutlets();
    }

    public static function set(int|string|null $outletId): bool
    {
        return self::context()->setOutlet($outletId);
    }

    public static function idForWrite(): int|string|null
    {
        return self::context()->outletForWrite();
    }

    private static function context(): Context
    {
        return app(Context::class);
    }
}
