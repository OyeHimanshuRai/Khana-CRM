<?php

namespace App\Console\Commands;

use App\Models\NightlyCheck;
use App\Services\NightlyChecks;
use App\Support\Outlets;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Tenancy\Entitlements;

/**
 * The nightly checks, for every outlet that holds ERP.
 *
 * Scheduled every hour rather than once a night, because "night" is each
 * outlet's own: the day that is checked is the trading day before the one
 * the outlet is in now, by its own timezone and its own cutoff. An outlet
 * that closes its day at 4 am in Delhi is checked in the first run after
 * that, and one in another timezone in the first run after its own.
 *
 * Running hourly is also the retry. A check that has finished for an outlet
 * and day is not run again, so most runs do nothing at all; one that ended
 * in a problem - CRM was down - is run again each hour until it is not.
 */
class RunNightlyChecks extends Command
{
    protected $signature = 'erp:nightly
        {outlet? : One outlet, by its id. Every outlet that holds ERP when left out.}
        {--date= : The trading day to check, YYYY-MM-DD. The day before each outlet\'s current one when left out.}
        {--force : Run every check again, even those already finished for that day.}';

    protected $description = 'Compare what ERP holds with CRM and Accounts for the trading day just closed, and put right what differs';

    public function handle(NightlyChecks $checks): int
    {
        $date = $this->option('date');

        if ($date !== null && ! self::isDay($date)) {
            $this->error('--date must be a day written YYYY-MM-DD.');

            return self::INVALID;
        }

        $outlets = $this->outlets();

        if ($outlets === []) {
            $this->components->info($this->argument('outlet') === null
                ? 'No outlet holds ERP: nothing to check.'
                : 'That outlet is not one ERP knows, or it does not hold ERP.');

            return $this->argument('outlet') === null ? self::SUCCESS : self::INVALID;
        }

        $problems = 0;

        foreach ($outlets as $outletId) {
            $today = Outlets::businessDate($outletId);
            $day = $date ?? CarbonImmutable::parse($today)->subDay()->toDateString();

            // A day still being traded is not checked: "all in order" said
            // of half a day would stand, and the real check never run.
            if ($day >= $today) {
                $this->components->warn(Outlets::name($outletId).': '.$day.' is not over yet there. Not checked.');

                continue;
            }

            $results = $checks->run($outletId, $day, (bool) $this->option('force'));

            // Read after the run: the reference check may have just renamed it.
            $this->line('');
            $this->line('<options=bold>'.Outlets::name($outletId).'</> · '.$day);

            foreach (array_keys(NightlyCheck::CHECKS) as $check) {
                ['row' => $row, 'ran' => $ran] = $results[$check];

                $this->components->twoColumnDetail($row->checkLabel(), $row->statusLabel().($ran ? '' : ' (checked earlier)'));

                if ($ran && $row->detail) {
                    $this->line('    <fg=gray>'.$row->detail.'</>');
                }

                if ($row->status === NightlyCheck::PROBLEM) {
                    $problems++;
                }
            }
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The outlets to check: the one named, or every one that holds ERP -
     * suspended ones too, whose books still have to add up.
     *
     * @return list<string>
     */
    private function outlets(): array
    {
        return DB::table('khana_outlets')
            ->join('khana_entitlements', 'khana_entitlements.outlet_id', '=', 'khana_outlets.id')
            ->where('khana_entitlements.product', 'erp')
            ->whereIn('khana_entitlements.state', Entitlements::HELD)
            ->when($this->argument('outlet'), fn ($q, $outlet) => $q->where('khana_outlets.id', $outlet))
            ->orderBy('khana_outlets.tenant_id')->orderBy('khana_outlets.id')
            ->pluck('khana_outlets.id')
            ->all();
    }

    private static function isDay(string $date): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
