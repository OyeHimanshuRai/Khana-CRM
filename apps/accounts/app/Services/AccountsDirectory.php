<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Khana\Kit\Tenancy\Directory;

/**
 * Which businesses and outlets exist, answered from Accounts' own tables.
 *
 * CRM and ERP answer the same questions from their read-only copy (the kit's
 * ReferenceDirectory); Accounts holds the originals, so it reads `tenants`
 * and `outlets` directly. Keys are the numeric ids used inside Accounts.
 */
final class AccountsDirectory implements Directory
{
    /**
     * @return list<int|string>
     */
    public function tenantKeys(): array
    {
        return DB::table('tenants')->orderBy('name')->pluck('id')->all();
    }

    /**
     * @return list<int|string>
     */
    public function outletKeys(int|string|null $tenantKey): array
    {
        return DB::table('outlets')
            ->when($tenantKey !== null, fn ($query) => $query->where('tenant_id', $tenantKey))
            ->orderBy('name')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<int|string>  $outletKeys
     * @return array<int|string, string>
     */
    public function outletNames(array $outletKeys): array
    {
        return $outletKeys === []
            ? []
            : DB::table('outlets')->whereIn('id', $outletKeys)->pluck('name', 'id')->all();
    }
}
