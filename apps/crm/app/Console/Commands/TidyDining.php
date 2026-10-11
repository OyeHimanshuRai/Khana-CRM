<?php

namespace App\Console\Commands;

use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Services\TableSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping for a dining floor that has been running a while.
 *
 * ---------------------------------------------------------------------------
 * Why this is a command and not a schedule
 * ---------------------------------------------------------------------------
 *
 * Everything here is a judgement about somebody else's restaurant: whether a
 * table that has been open for two days had a party at it, whether a table
 * marked busy really is. A nightly job that decided those on its own would
 * close a long dinner and clear a flag somebody had just set.
 *
 * So it reports by default and only changes what it is explicitly told to,
 * one flag per kind of change. Run it, read it, then run it again with the
 * flag for the part you agree with.
 *
 * ---------------------------------------------------------------------------
 * What it finds
 * ---------------------------------------------------------------------------
 *
 *   sittings   A table session opened and never closed. Nothing ends one on
 *              its own - a computer cannot know a party went home - so a
 *              sitting a busy evening forgot keeps its table occupied, keeps
 *              its kitchen tickets, and blocks the next booking for ever.
 *
 *   tables     A table flagged occupied or billing with no sitting under it.
 *              The flag is set by hand and drifts; where it says a table is
 *              busy and no bill exists, the flag is the one that is wrong.
 */
class TidyDining extends Command
{
    protected $signature = 'dine:tidy
                            {--shop= : Limit to one shop id}
                            {--sittings= : Close sittings idle longer than this many hours}
                            {--tables : Clear occupied/billing flags on tables with no sitting}';

    protected $description = 'Report - and on request, clear - stuck sittings and stale table flags';

    public function handle(TableSessionService $sessions): int
    {
        $shops = $this->option('shop')
            ? Shop::query()->whereKey((int) $this->option('shop'))->get()
            : Shop::query()->active()->get();

        if ($shops->isEmpty()) {
            $this->error($this->option('shop') ? 'No shop with that id.' : 'No active shops.');

            return self::FAILURE;
        }

        $found = false;

        foreach ($shops as $shop) {
            $this->line('');
            $this->info($shop->name);

            $found = $this->sittings($shop, $sessions) || $found;
            $found = $this->tables($shop) || $found;
        }

        $this->line('');

        if (! $found) {
            $this->info('Nothing to tidy.');
        } elseif (! $this->option('sittings') && ! $this->option('tables')) {
            $this->comment('Nothing was changed. Re-run with --sittings=24 or --tables to act on the above.');
        }

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------- sittings */

    private function sittings(Shop $shop, TableSessionService $sessions): bool
    {
        $hours = (int) ($this->option('sittings') ?: 24);

        $stale = TableSession::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->openOrBilled()
            ->where('opened_at', '<', now()->subHours($hours))
            ->with('table:id,code,name')
            ->orderBy('opened_at')
            ->get();

        if ($stale->isEmpty()) {
            return false;
        }

        $this->line(sprintf('  %d sitting(s) open longer than %d hours:', $stale->count(), $hours));

        foreach ($stale as $session) {
            $this->line(sprintf(
                '    %-8s %-22s %4d h   %d order(s)',
                $session->table?->code ?? '?',
                $session->partyName(),
                (int) round($session->seatedMinutes() / 60),
                $session->orders()->count(),
            ));
        }

        if (! $this->option('sittings')) {
            return true;
        }

        /*
         | Closed, never settled.
         |
         | The money is deliberately left alone. A bill from two days ago
         | cannot be collected by a command, and raising an invoice nobody
         | will ever be paid for would put revenue in the accounts that the
         | restaurant never took. Somebody who wants that decision recorded
         | writes the table off from Table Bills, which is where writing off
         | belongs and where it asks for a reason.
         */
        foreach ($stale as $session) {
            $sessions->close($session, sprintf('Closed by dine:tidy after %d hours open', $hours));
        }

        $this->info(sprintf('  Closed %d sitting(s). No bill was raised or written off.', $stale->count()));

        return true;
    }

    /* ------------------------------------------------------------ tables */

    private function tables(Shop $shop): bool
    {
        $drifted = RestaurantTable::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->whereIn('status', [RestaurantTable::OCCUPIED, RestaurantTable::BILLING])
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('table_sessions')
                ->whereColumn('table_sessions.restaurant_table_id', 'restaurant_tables.id')
                ->whereIn('table_sessions.status', [TableSession::OPEN, TableSession::BILLED]))
            ->get();

        if ($drifted->isEmpty()) {
            return false;
        }

        $this->line(sprintf(
            '  %d table(s) flagged busy with no sitting: %s',
            $drifted->count(),
            $drifted->pluck('code')->implode(', '),
        ));

        if (! $this->option('tables')) {
            return true;
        }

        DB::transaction(fn () => $drifted->each(
            fn (RestaurantTable $table) => $table->forceFill(['status' => RestaurantTable::AVAILABLE])->save()
        ));

        $this->info(sprintf('  Freed %d table(s).', $drifted->count()));

        return true;
    }
}
