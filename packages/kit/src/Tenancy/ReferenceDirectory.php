<?php

namespace Khana\Kit\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Businesses and outlets from this service's read-only copy of Accounts.
 */
final class ReferenceDirectory implements Directory
{
    public function tenantKeys(): array
    {
        return DB::table('khana_tenants')->orderBy('name')->orderBy('id')->pluck('id')->all();
    }

    public function outletKeys(int|string|null $tenantKey): array
    {
        return DB::table('khana_outlets')
            ->when($tenantKey !== null, fn ($query) => $query->where('tenant_id', $tenantKey))
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
