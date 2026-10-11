<?php

namespace Khana\Kit\Events\Inbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Khana\Kit\Tenancy\Entitlements;
use Khana\Kit\Tenancy\TenantMismatch;

/**
 * Checks a business event's tenant and outlet against the local copy of
 * Accounts before anything is applied.
 *
 *  - An outlet this service has not heard of yet: wait (RetryLater). The
 *    accounts.outlet event is usually a few seconds behind.
 *  - An outlet that belongs to a different business than the event claims:
 *    refused (TenantMismatch), and the stream stops until somebody looks.
 *  - An outlet that has not bought this product: wait while nothing is known
 *    (the entitlement event may still be on its way), ignore once the
 *    outlet has ended it.
 *
 * The signature already proves which service sent an event. This proves the
 * event is about somebody that service may speak for.
 *
 * Accounts' own events and the diagnostic ping are what build the copy, so
 * they pass untouched.
 */
final class ReferenceGuard
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @return string|null why to ignore the event, or null to apply it
     *
     * @throws RetryLater|TenantMismatch
     */
    public function check(InboxEvent $event): ?string
    {
        if (! $this->active() || str_starts_with($event->type, 'accounts.') || str_starts_with($event->type, 'system.')) {
            return null;
        }

        $product = (string) config('khana.service');

        if ($event->outlet_id !== null) {
            $owner = DB::table('khana_outlets')->where('id', $event->outlet_id)->value('tenant_id');

            if ($owner === null) {
                throw new RetryLater("Outlet {$event->outlet_id} is not known here yet.");
            }

            if ($owner !== $event->tenant_id) {
                throw new TenantMismatch("Event {$event->event_id} puts outlet {$event->outlet_id} under business {$event->tenant_id}; it belongs to {$owner}.");
            }

            $state = $this->entitlements->state($event->outlet_id, $product);

            if ($state === null) {
                throw new RetryLater("Outlet {$event->outlet_id} has no {$product} entitlement here yet.");
            }

            return $this->entitlements->owns($event->outlet_id, $product)
                ? null
                : "Outlet {$event->outlet_id} no longer has {$product} ({$state}).";
        }

        if ($event->tenant_id !== null && ! DB::table('khana_tenants')->where('id', $event->tenant_id)->exists()) {
            throw new RetryLater("Business {$event->tenant_id} is not known here yet.");
        }

        return null;
    }

    private function active(): bool
    {
        return config('khana.service') !== 'accounts' && Schema::hasTable('khana_outlets');
    }
}
