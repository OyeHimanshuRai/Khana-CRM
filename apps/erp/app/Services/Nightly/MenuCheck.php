<?php

namespace App\Services\Nightly;

use App\Services\Crm\CrmUnavailable;
use App\Services\MenuSync;
use Khana\Kit\Tenancy\Entitlements;

/**
 * The nightly menu check: ERP's copy of the dishes against CRM's menu.
 *
 * Comparing and repairing are one act here. MenuSync pulls CRM's whole menu
 * and makes ERP's copy match, and says what it had to add, change or put
 * away - which is exactly the list of menu events that went missing.
 *
 * Only for an outlet that holds both products. One that bills with
 * something else has no menu in CRM, and CRM is not asked.
 */
final class MenuCheck
{
    public function __construct(
        private readonly MenuSync $menus,
        private readonly Entitlements $entitlements,
    ) {}

    public function run(string $outletId): Finding
    {
        if (! $this->entitlements->isSuite($outletId)) {
            return Finding::skipped('This outlet does not use Khana CRM, so there is no menu to compare.');
        }

        try {
            $counts = $this->menus->pull($outletId);
        } catch (CrmUnavailable $e) {
            return Finding::problem(0, 0, 'CRM could not be reached, so the menu was not compared. '.$e->getMessage().' ERP tries again every hour.');
        }

        $differed = array_sum($counts);

        return $differed === 0
            ? Finding::ok('ERP has the menu as CRM has it.')
            : Finding::repaired($differed, MenuSync::describe($counts));
    }
}
