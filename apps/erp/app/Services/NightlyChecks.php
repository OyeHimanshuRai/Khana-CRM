<?php

namespace App\Services;

use App\Models\NightlyCheck;
use App\Services\Nightly\BalancesCheck;
use App\Services\Nightly\Finding;
use App\Services\Nightly\MenuCheck;
use App\Services\Nightly\ReferenceCheck;
use App\Services\Nightly\SalesCheck;
use App\Support\Outlets;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly checks for one outlet and one trading day: run the ones that
 * are due, and write down what each found.
 *
 * Events carry everything live, and the plumbing under them retries until
 * each is delivered. This is for what the plumbing cannot see - an event
 * that was never recorded, or was thrown away - and it is how such a thing
 * is found and put right by morning.
 *
 * The checks run in the order each depends on the one before, which is not
 * the order they are listed in for a person to read:
 *
 *   reference  first, because what an outlet has bought decides whether
 *              there is a CRM to compare with at all;
 *   menu       next, because a sale fetched from CRM is recorded against
 *              the dishes ERP knows;
 *   sales      then the day's bills, which move stock when one was missing;
 *   balances   last, once everything that was going to move has moved.
 *
 * A check that ended ok, repaired or skipped is finished for that outlet
 * and day and is left alone. One that ended in a problem is run again on
 * the next pass, and its row updated - so CRM being down at five in the
 * morning is a problem at five and repaired at six.
 */
class NightlyChecks
{
    public const ORDER = [NightlyCheck::REFERENCE, NightlyCheck::MENU, NightlyCheck::SALES, NightlyCheck::BALANCES];

    public function __construct(
        private readonly ReferenceCheck $reference,
        private readonly MenuCheck $menu,
        private readonly SalesCheck $sales,
        private readonly BalancesCheck $balances,
    ) {}

    /**
     * @return array<string, array{row: NightlyCheck, ran: bool}> check => its row, and whether this call ran it
     */
    public function run(string $outletId, string $date, bool $force = false): array
    {
        $tenantId = Outlets::tenantOf($outletId);
        $key = fn (string $check) => ['outlet_id' => $outletId, 'business_date' => $date, 'check' => $check];
        $results = [];

        foreach (self::ORDER as $check) {
            $row = NightlyCheck::allOutlets()->where($key($check))->first();

            if ($row !== null && $row->isFinal() && ! $force) {
                $results[$check] = ['row' => $row, 'ran' => false];

                continue;
            }

            $finding = $this->check($check, $outletId, $date);

            if ($finding->isProblem()) {
                Log::warning('A nightly check needs a look.', $key($check) + ['detail' => $finding->detail]);
            }

            $results[$check] = ['ran' => true, 'row' => NightlyCheck::allOutlets()->updateOrCreate($key($check), [
                'tenant_id' => $tenantId,
                'status' => $finding->status,
                'found' => $finding->found,
                'repaired' => $finding->repaired,
                'detail' => $finding->detail,
                'ran_at' => now(),
            ])];
        }

        return $results;
    }

    /**
     * One check, which is never allowed to stop the others: whatever it
     * throws is reported, and written down as a problem to try again.
     */
    private function check(string $check, string $outletId, string $date): Finding
    {
        try {
            return match ($check) {
                NightlyCheck::REFERENCE => $this->reference->run($outletId),
                NightlyCheck::MENU => $this->menu->run($outletId),
                NightlyCheck::SALES => $this->sales->run($outletId, $date),
                NightlyCheck::BALANCES => $this->balances->run($outletId),
            };
        } catch (Throwable $e) {
            report($e);

            return Finding::problem(0, 0, 'The check stopped before it finished, and has been reported. ERP tries again every hour.');
        }
    }
}
