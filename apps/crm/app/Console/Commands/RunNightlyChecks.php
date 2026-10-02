<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\NightlyCheck;
use App\Models\Shop;
use App\Services\AlertService;
use App\Services\NightlyChecks;
use App\Support\BusinessDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Tenancy\Entitlements;

/**
 * The nightly checks, for every outlet of this business that holds ERP.
 *
 * Scheduled every hour rather than once a night, because "night" is each
 * outlet's own and because running hourly is also the retry: a check that
 * ended in a problem - ERP could not be reached - is run again each hour
 * until it is not. The availability check reconciles on every pass anyway,
 * since stock moves all day.
 */
class RunNightlyChecks extends Command
{
    protected $signature = 'crm:nightly
        {outlet? : One outlet, by its Accounts id. Every outlet that holds ERP when left out.}
        {--date= : The trading day to record against, YYYY-MM-DD. The outlet\'s current one when left out.}
        {--force : Run every check again, even those already finished for that day.}';

    protected $description = 'Compare what CRM holds with ERP for each outlet, and put right what a lost event left out of step';

    public function handle(NightlyChecks $checks, AlertService $alerts): int
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
                : 'That outlet is not one CRM knows, or it does not hold ERP.');

            return $this->argument('outlet') === null ? self::SUCCESS : self::INVALID;
        }

        $problems = 0;

        foreach ($outlets as $outletId) {
            $shop = Shop::query()->where('ulid', $outletId)->first();
            $day = $date ?? BusinessDay::today($shop)->toDateString();

            $results = $checks->run($outletId, $day, (bool) $this->option('force'));

            $this->line('');
            $this->line('<options=bold>'.($shop->name ?? $outletId).'</> · '.$day);

            foreach (array_keys(NightlyCheck::CHECKS) as $check) {
                ['row' => $row, 'ran' => $ran] = $results[$check];

                $this->components->twoColumnDetail($row->checkLabel(), $row->statusLabel().($ran ? '' : ' (checked earlier)'));

                if ($ran && $row->detail) {
                    $this->line('    <fg=gray>'.$row->detail.'</>');
                }

                // A problem rings a bell on the home screen, so a lost event
                // that could not put itself right is seen, not just logged; a
                // check that is no longer a problem clears its own.
                if ($row->status === NightlyCheck::PROBLEM) {
                    $problems++;

                    if ($shop !== null) {
                        $alerts->raise(
                            $shop,
                            type: Alert::NIGHTLY_PROBLEM,
                            title: 'Nightly check needs a look: '.$row->checkLabel(),
                            dedupeKey: 'nightly:'.$check.':'.$outletId,
                            body: $row->detail,
                            can: 'dashboard.overview.view',
                            level: 'warning',
                        );
                    }
                } elseif ($shop !== null) {
                    $alerts->clear($shop, 'nightly:'.$check.':'.$outletId);
                }
            }
        }

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The outlets to check: the one named, or every one of a business that
     * holds ERP - suspended ones too, whose menu still has to match.
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
