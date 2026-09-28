<?php

namespace App\Providers;

use App\Events\Inbound\ApplyAvailability;
use App\Events\Inbound\ApplyCost;
use App\Events\Outbound\MenuChanges;
use App\Events\Outbound\MenuWatch;
use Illuminate\Support\ServiceProvider;
use Khana\Kit\Events\Inbox\Handlers;

/**
 * CRM's side of the conversation with ERP, wired in one place.
 *
 *   out   crm.* events, recorded by App\Events\Outbound\CrmEvents from the
 *         services that make each change (InvoiceService, KitchenService,
 *         SalesReturnService, OrderService, CashRegisterService) and, for the
 *         menu, from the model hooks in MenuWatch. Who receives them is
 *         config/khana.php's business: ERP, for outlets that own ERP.
 *
 *   in    erp.availability.changed and erp.cost.changed, applied by the
 *         handlers in App\Events\Inbound. The kit stores what ERP sends,
 *         checks the outlet against the copy of Accounts, and calls these in
 *         order, one outlet at a time.
 *
 *   read  the machine-token API in routes/api.php (contracts/api/crm.yaml).
 *
 * Its own provider rather than lines in AppServiceProvider, so the whole
 * integration reads as one thing and can be found by its name.
 */
final class ErpIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One request's (or one job's) notes of which dishes changed.
        $this->app->scoped(MenuChanges::class);
    }

    public function boot(): void
    {
        $handlers = $this->app->make(Handlers::class);

        $handlers->on('erp.availability.changed', 1, ApplyAvailability::class);
        $handlers->on('erp.cost.changed', 1, ApplyCost::class);

        MenuWatch::register();
    }
}
