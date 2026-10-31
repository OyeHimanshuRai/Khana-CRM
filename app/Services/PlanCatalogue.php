<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Shop;
use App\Support\Modules;
use App\Support\PlanAccess;

/**
 * Changing what a plan includes, wherever the platform does it from.
 *
 * Taking a module off a plan needs nothing more than the save: the plan is read
 * live, so its screens close on the next request. Adding one needs a step
 * more. An outlet that named its own modules would otherwise never see it, and
 * the platform would have sold something nobody received - so it is added to
 * those lists too. That cannot override a choice: an outlet could not have
 * switched off a module its plan did not include.
 */
class PlanCatalogue
{
    /** @param  array<int, string>  $modules */
    public function setModules(Plan $plan, array $modules): void
    {
        $before = $plan->moduleKeys();

        $plan->forceFill(['modules' => Modules::sanitise($modules)])->save();

        $added = array_values(array_diff($plan->moduleKeys(), $before));

        PlanAccess::forget();

        if ($added !== []) {
            $this->offerToOutlets($plan, $added);
        }

        Modules::forget();
    }

    /** @param  array<int, string>  $added */
    private function offerToOutlets(Plan $plan, array $added): void
    {
        Shop::query()->withoutGlobalScopes()->whereNotNull('modules')->get()
            ->filter(fn (Shop $shop) => PlanAccess::subscriptionForShop($shop->id)?->plan_id === $plan->id)
            ->each(fn (Shop $shop) => $shop->forceFill([
                'modules' => array_values(array_unique([...$shop->modules, ...$added])),
            ])->saveQuietly());
    }
}
