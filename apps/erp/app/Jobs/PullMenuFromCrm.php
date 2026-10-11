<?php

namespace App\Jobs;

use App\Services\Crm\CrmUnavailable;
use App\Services\MenuSync;
use App\Support\Outlets;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Tenancy\Entitlements;
use Throwable;

/**
 * The first menu sync: an outlet has just come to hold both CRM and ERP, and
 * ERP pulls the whole menu once. After that the events keep it current.
 *
 * Queued by App\Services\ReferenceSync when the entitlement arrives, after
 * that work has committed - so CRM is asked from here, outside any
 * transaction, and an event is never held up waiting on another service.
 *
 * Nothing that goes wrong here is passed on. CRM being down is expected and
 * only logged; anything else is reported. Either way the nightly check pulls
 * the menu for every outlet that holds both, so tonight is the retry - and on
 * a queue that runs jobs where they are dispatched, a failure thrown from
 * here would land on the event that was just applied.
 */
final class PullMenuFromCrm implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $outletId) {}

    public function handle(MenuSync $menus, Entitlements $entitlements): void
    {
        try {
            // Looked at again here, not trusted from when it was queued: the
            // outlet may have been synced since, or may have given CRM up.
            if (Outlets::find($this->outletId) === null
                || ! $entitlements->isSuite($this->outletId)
                || Outlets::settings($this->outletId)->menu_synced_at !== null) {
                return;
            }

            $menus->pull($this->outletId);
        } catch (CrmUnavailable $e) {
            Log::warning('The first menu sync could not reach CRM; the nightly check will pull the menu.', [
                'outlet_id' => $this->outletId,
                'reason' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }

            report($e);
        }
    }
}
