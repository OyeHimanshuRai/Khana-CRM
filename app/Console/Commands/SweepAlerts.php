<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\AlertService;
use Illuminate\Console\Command;

/**
 * Raise the notifications SRS 15 asks the system to work out for itself.
 *
 * Low stock, tills left open past their own business day, and table sittings
 * nobody ever closed. All three are "look at the state of things and say what
 * is wrong", which is a scheduled job, not an event.
 *
 * Safe to run as often as you like. AlertService writes through a unique
 * dedupe key, so a second run in the same day updates rather than
 * duplicates - and a run that crashed halfway can simply be run again.
 *
 * Runs across every branch, because the console has no shop context and a
 * nightly sweep that only saw one would leave the rest silent.
 */
class SweepAlerts extends Command
{
    protected $signature = 'alerts:sweep
                            {--shop= : Limit to one shop id}
                            {--prune : Also delete alerts whose expiry has passed}';

    protected $description = 'Raise low-stock, day-close and stuck-table notifications';

    public function handle(AlertService $alerts): int
    {
        $shops = $this->option('shop')
            ? Shop::query()->whereKey((int) $this->option('shop'))->get()
            : Shop::query()->active()->get();

        if ($shops->isEmpty()) {
            $this->error(
                $this->option('shop')
                    ? 'No shop with that id.'
                    : 'No active shops to sweep.'
            );

            return self::FAILURE;
        }

        $totals = [];

        foreach ($shops as $shop) {
            $raised = $alerts->sweep($shop);

            foreach ($raised as $kind => $count) {
                $totals[$kind] = ($totals[$kind] ?? 0) + $count;
            }

            $this->line(sprintf(
                '  %-24s %s',
                $shop->name,
                collect($raised)->filter()->map(fn ($n, $k) => "{$k}: {$n}")->implode(', ') ?: 'nothing',
            ));
        }

        $this->info(sprintf(
            'Swept %d branch(es); raised %d notification(s).',
            $shops->count(),
            array_sum($totals),
        ));

        if ($this->option('prune')) {
            $pruned = $alerts->prune();

            $this->info("Pruned {$pruned} expired notification(s).");
        }

        return self::SUCCESS;
    }
}
