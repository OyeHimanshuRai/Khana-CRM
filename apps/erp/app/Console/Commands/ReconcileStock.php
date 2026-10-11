<?php

namespace App\Console\Commands;

use App\Services\StockService;
use Illuminate\Console\Command;

/**
 * The nightly balance check: every stock level must equal the sum of its
 * movements.
 *
 * There should never be a difference - StockService is the only writer and
 * writes both together. This is what proves it, every night, and what says
 * so loudly on the night it stops being true.
 */
class ReconcileStock extends Command
{
    protected $signature = 'erp:reconcile {outlet? : One outlet, by its id. All of them when left out.}';

    protected $description = 'Check that every stock level equals the sum of its movements';

    public function handle(StockService $stock): int
    {
        $wrong = $stock->reconcile($this->argument('outlet'));

        if ($wrong === []) {
            $this->info('Every stock level equals its movements.');

            return self::SUCCESS;
        }

        $this->error(count($wrong).' stock level(s) do not equal their movements:');
        $this->table(['Level', 'Level says', 'Movements say'], array_map(
            fn (array $row) => [$row['level_id'], $row['level'], $row['movements']],
            $wrong,
        ));

        report(new \RuntimeException(count($wrong).' ERP stock levels do not equal their movements.'));

        return self::FAILURE;
    }
}
