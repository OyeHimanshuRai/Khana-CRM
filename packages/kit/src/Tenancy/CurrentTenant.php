<?php

namespace Khana\Kit\Tenancy;

/**
 * Shorthand for the business half of the request's Context.
 */
final class CurrentTenant
{
    public static function id(): int|string|null
    {
        return self::context()->tenantId();
    }

    /**
     * @return list<int|string>
     */
    public static function accessibleIds(): array
    {
        return self::context()->accessibleTenants();
    }

    public static function canAccess(int|string $tenantId): bool
    {
        return self::context()->canAccessTenant($tenantId);
    }

    public static function set(int|string|null $tenantId): bool
    {
        return self::context()->setTenant($tenantId);
    }

    private static function context(): Context
    {
        return app(Context::class);
    }
}
