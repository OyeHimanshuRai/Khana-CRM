<?php

namespace Database\Seeders;

use App\Http\Middleware\EnsureShopIsEntitled;
use App\Models\Shop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\Entitlements;

/**
 * Gives every shop CRM, the way Accounts would once it had sold it.
 *
 * ---------------------------------------------------------------------------
 * Why a seeder writes a row that belongs to Accounts
 * ---------------------------------------------------------------------------
 *
 * `khana_entitlements` is the kit's copy of what each outlet bought, and in a
 * running system only accounts.entitlement.changed writes it. A development
 * database and the test baseline have no Accounts talking to them - so
 * without this, EnsureShopIsEntitled would refuse every login that is not a
 * Super Admin the moment seeding finished.
 *
 * So it writes exactly what that event would: one `crm` row per shop, keyed
 * by the shop's ulid (Accounts' outlet id) and its business's ulid, active on
 * a `dev` plan. A shop that already has a row is left alone - that row came
 * from Accounts, or from a test that wanted a particular state.
 *
 * Never in production. There the rows are Accounts' to write, and a fallback
 * that handed every outlet CRM for free would be the wrong kind of helpful.
 *
 * Run it on its own to bring an existing development database up to date:
 *
 *     php artisan db:seed --class=CrmEntitlementSeeder
 */
class CrmEntitlementSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('  Skipped: entitlements come from Khana Accounts in production.');

            return;
        }

        $held = DB::table('khana_entitlements')
            ->where('product', EnsureShopIsEntitled::PRODUCT)
            ->pluck('outlet_id')
            ->all();

        $entitled = 0;

        foreach (Shop::query()->withoutGlobalScopes()->with('tenant')->orderBy('id')->get() as $shop) {
            if ($shop->ulid !== null && in_array($shop->ulid, $held, true)) {
                continue;
            }

            $entitled += (int) self::entitle($shop);
        }

        $this->command?->info(sprintf('  %d %s entitled to CRM.', $entitled, $entitled === 1 ? 'shop' : 'shops'));
    }

    /**
     * Put one shop's CRM entitlement in the given state.
     *
     * Also what the tests use - every shop a test creates goes through here
     * (see Tests\TestCase), and a test about the gate itself calls it again
     * with 'suspended' or 'ended'.
     *
     * A shop or business with no ulid yet gets one first. Rows made with
     * model events off - the seeders run that way - never met the hook that
     * gives every other row its Accounts id, and an entitlement cannot be
     * keyed on nothing.
     *
     * @return bool false when the shop belongs to no business, which no
     *              outlet in Accounts can
     */
    public static function entitle(Shop $shop, string $state = 'active'): bool
    {
        $tenant = $shop->tenant;

        if ($tenant === null) {
            return false;
        }

        foreach ([$tenant, $shop] as $row) {
            if (blank($row->ulid)) {
                $row->forceFill(['ulid' => Ulid::generate()])->saveQuietly();
            }
        }

        DB::table('khana_entitlements')->updateOrInsert(
            ['outlet_id' => $shop->ulid, 'product' => EnsureShopIsEntitled::PRODUCT],
            [
                'tenant_id' => $tenant->ulid,
                'state' => $state,
                'plan' => 'dev',
                'limits' => '{}',
                'valid_until' => null,
                'changed_at' => now()->utc()->format('Y-m-d H:i:s'),
            ],
        );

        // The answer is memoised per request; this one just changed.
        app(Entitlements::class)->forget();

        return true;
    }
}
