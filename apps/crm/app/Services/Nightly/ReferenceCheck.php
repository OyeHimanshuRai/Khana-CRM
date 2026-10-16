<?php

namespace App\Services\Nightly;

use App\Services\Accounts\AccountsUnavailable;
use App\Services\ReferenceSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Tenancy\TenantMismatch;

/**
 * The nightly reference check: CRM's copy of the business, its outlets and
 * what each has bought, against Accounts.
 *
 * The copy belongs to the business, not to one outlet, so Accounts is asked
 * once for each business in a run and every outlet of it gets the same answer.
 * ReferenceSync does the comparing and the repairing in one go, with the code
 * that applies the live events.
 *
 * It is entitlements this guards most: a lost accounts.entitlement.changed
 * could leave an outlet open after its CRM ended, or shut after it renewed.
 * What CRM holds that Accounts does not list - an outlet, a plan - is a
 * problem for a person. It is named, and it is left exactly where it is.
 */
final class ReferenceCheck
{
    /** @var array<string, Finding> business => what this run found for it */
    private array $found = [];

    public function __construct(private readonly ReferenceSync $reference) {}

    public function run(string $outletId): Finding
    {
        // A standalone install has no Accounts to read a snapshot from - the
        // service-to-service address is where it would live. Nothing to compare.
        if (blank(config('khana.urls.accounts'))) {
            return Finding::skipped('This install is not linked to Restro One Accounts.');
        }

        $tenantId = DB::table('khana_outlets')->where('id', $outletId)->value('tenant_id');

        if (! is_string($tenantId) || $tenantId === '') {
            return Finding::skipped('CRM does not know which business this outlet belongs to yet.');
        }

        return $this->found[$tenantId] ??= $this->compare($tenantId);
    }

    private function compare(string $tenantId): Finding
    {
        try {
            $result = $this->reference->pull($tenantId);
        } catch (AccountsUnavailable $e) {
            return Finding::problem(0, 0, 'Accounts could not be reached, so the business details were not compared. '.$e->getMessage().' CRM tries again every hour.');
        } catch (TenantMismatch $e) {
            Log::error('The nightly check found an outlet under two businesses.', ['tenant_id' => $tenantId, 'reason' => $e->getMessage()]);

            return Finding::problem(1, 0, 'Accounts lists an outlet under this business that CRM holds under another. Nothing was changed; this needs Restro One support.');
        }

        $changed = $result['changed'];
        $strays = $result['strays'];

        $fixed = $changed === 0 ? '' : ($changed === 1 ? '1 detail' : $changed.' details').' that differed from Accounts '.($changed === 1 ? 'was' : 'were').' brought in line.';

        if ($strays !== []) {
            return Finding::problem($changed + count($strays), $changed, trim(implode(' ', $strays).' Nothing was removed. '.$fixed));
        }

        return $changed === 0
            ? Finding::ok('The business, its outlets and plans are as Accounts has them.')
            : Finding::repaired($changed, ucfirst($fixed));
    }
}
