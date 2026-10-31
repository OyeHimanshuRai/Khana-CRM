<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Services\StockService;
use App\Services\TableSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Housekeeping for a dining floor that has been running a while.
 *
 * ---------------------------------------------------------------------------
 * Why this is a command and not a schedule
 * ---------------------------------------------------------------------------
 *
 * Everything here is a judgement about somebody else's restaurant: whether a
 * table that has been open for two days had a party at it, whether a count
 * against a dish was ever meant to be there. A nightly job that decided those
 * on its own would close a long dinner and delete a figure somebody was using.
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
 *   dishes     A shelf quantity against a made-to-order dish. A restaurant has
 *              no count of Butter Naan and no sale moves one, so any figure
 *              there can only ever be wrong - see Product::tracksStock(). The
 *              usual source is demo or imported data that bought finished
 *              dishes from a supplier.
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
                            {--dishes : Zero the stock held against made-to-order dishes}
                            {--tables : Clear occupied/billing flags on tables with no sitting}';

    protected $description = 'Report - and on request, clear - stuck sittings, phantom dish stock and stale table flags';

    public function handle(TableSessionService $sessions, StockService $stock): int
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
            $found = $this->dishes($shop, $stock) || $found;
            $found = $this->tables($shop) || $found;
        }

        $this->line('');

        if (! $found) {
            $this->info('Nothing to tidy.');
        } elseif (! $this->option('sittings') && ! $this->option('dishes') && ! $this->option('tables')) {
            $this->comment('Nothing was changed. Re-run with --sittings=24, --dishes or --tables to act on the above.');
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

    /* ------------------------------------------------------------ dishes */

    private function dishes(Shop $shop, StockService $stock): bool
    {
        $slots = ProductStock::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->where('quantity', '<>', 0)
            ->whereIn('product_id', Product::query()->where('is_made_to_order', true)->select('id'))
            ->with(['product:id,name,is_made_to_order', 'warehouse', 'batch'])
            ->get();

        if ($slots->isEmpty()) {
            return false;
        }

        $this->line(sprintf(
            '  %d stock row(s) held against made-to-order dishes:',
            $slots->count(),
        ));

        foreach ($slots->take(10) as $slot) {
            $this->line(sprintf('    %-30s %10s', $slot->product?->name ?? '?', $slot->quantity));
        }

        if ($slots->count() > 10) {
            $this->line(sprintf('    ... and %d more', $slots->count() - 10));
        }

        if (! $this->option('dishes')) {
            return true;
        }

        /*
         | Counted to zero, not deleted.
         |
         | adjustTo writes a real movement with a reason on it, so the stock
         | ledger still explains where the figure went. Deleting the rows
         | would leave a product whose movement history adds up to something
         | its current quantity does not, which is the one thing an audit
         | trail exists to prevent.
         */
        $cleared = 0;

        foreach ($slots as $slot) {
            if ($slot->product === null) {
                continue;
            }

            try {
                $stock->adjustTo(
                    product: $slot->product,
                    countedQuantity: 0.0,
                    reason: 'Made to order: a dish has no shelf to count (dine:tidy)',
                    warehouse: $slot->warehouse,
                    batch: $slot->batch,
                    shopId: $shop->id,
                );

                $cleared++;
            } catch (Throwable $e) {
                $this->warn(sprintf('    %s could not be cleared: %s', $slot->product->name, $e->getMessage()));
            }
        }

        $this->info(sprintf('  Cleared %d dish stock row(s).', $cleared));

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
