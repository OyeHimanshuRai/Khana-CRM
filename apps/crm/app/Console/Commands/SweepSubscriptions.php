<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;

/**
 * Make lapsed accounts look lapsed (SRS 21).
 *
 * ---------------------------------------------------------------------------
 * This command does not enforce anything
 * ---------------------------------------------------------------------------
 *
 * Worth being plain about, because the name suggests otherwise. Expiry is
 * enforced on every request by EnsureTenantIsActive, from Subscription
 * ::state(), which reads the clock. A business whose term ended at midnight
 * is locked out at one minute past whether this command has run this year or
 * not.
 *
 * What the sweep does is housekeeping that nothing else can do: it flips the
 * tenant's `is_active` flag, so the account reads as suspended on the tenant
 * list, in the switcher and in every existing query that already checks it -
 * without those forty places having to learn what a subscription is.
 *
 * The consequence is the point: if this command silently stops running, the
 * platform keeps collecting and keeps refusing the right people. It gets
 * stale screens, not free service.
 *
 * Idempotent. A tenant already suspended is skipped, so running it twice, or
 * running a week's worth of missed runs at once, changes nothing extra.
 */
class SweepSubscriptions extends Command
{
    protected $signature = 'subscriptions:sweep
                            {--warn=7 : Also list subscriptions ending within this many days}
                            {--dry-run : Report what would be suspended without touching anything}';

    protected $description = 'Suspend tenants whose subscription has lapsed, and list the ones about to';

    public function handle(SubscriptionService $subscriptions): int
    {
        $warnDays = max(0, (int) $this->option('warn'));

        /* ------------------------------------------------------- expiring */

        if ($warnDays > 0) {
            $expiring = $subscriptions->expiringWithin($warnDays);

            if ($expiring->isNotEmpty()) {
                $this->line('Ending within '.$warnDays.' days:');

                $this->table(
                    ['Company', 'Plan', 'Ends', 'Days left'],
                    $expiring->map(fn (Subscription $s) => [
                        $s->tenant?->name ?? '—',
                        $s->plan?->name ?? '—',
                        $s->ends_at?->format('j M Y') ?? '—',
                        (string) ($s->daysLeft() ?? '—'),
                    ])->all(),
                );
            }
        }

        /* ------------------------------------------------------- lapsed */

        if ($this->option('dry-run')) {
            // The sweep's own query, reported instead of acted on.
            $would = $subscriptions->pendingSuspension();

            $this->info($would->count().' tenant(s) would be suspended.');

            foreach ($would as $s) {
                $this->line('  '.$s->tenant->name.' — expired '.($s->ends_at?->format('j M Y') ?? '?'));
            }

            return self::SUCCESS;
        }

        $acted = $subscriptions->sweep();

        if ($acted->isEmpty()) {
            $this->info('Nothing to suspend.');

            return self::SUCCESS;
        }

        foreach ($acted as $tenant) {
            $this->warn('Suspended: '.$tenant->name.' — '.$tenant->suspend_reason);
        }

        $this->info($acted->count().' tenant(s) suspended.');

        return self::SUCCESS;
    }
}
