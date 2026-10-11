<?php

namespace App\Services;

use App\Models\NightlyCheck;
use App\Models\Shop;
use App\Services\Nightly\AvailabilityCheck;
use App\Services\Nightly\Finding;
use App\Services\Nightly\ReferenceCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly checks for one outlet and one trading day: run the ones that
 * are due, and write down what each found.
 *
 * Events carry everything live, and the plumbing under them retries until
 * each is delivered. This is for what the plumbing cannot see - an event that
 * was never recorded, or was thrown away - and it is how such a thing is found
 * and put right by morning.
 *
 * Only `availability` runs today; the shape is kept open for `reference` and
 * `balances` to join it. Add a check to ORDER, give it a branch in check(),
 * and - if it is one that is finished once it has run for a day rather than
 * one that must be redone each pass - name it in ONCE_PER_DAY.
 */
class NightlyChecks
{
    public const ORDER = [NightlyCheck::REFERENCE, NightlyCheck::AVAILABILITY];

    /**
     * Checks finished once they have run for a day, re-run only with --force.
     *
     * Reference is one: it is the business snapshot from Accounts, and once it
     * has matched for the day there is nothing to re-ask. Availability is not:
     * stock moves all day, so it is reconciled on every pass and its row is
     * overwritten with the latest answer.
     *
     * @var list<string>
     */
    private const ONCE_PER_DAY = [NightlyCheck::REFERENCE];

    public function __construct(
        private readonly ReferenceCheck $reference,
        private readonly AvailabilityCheck $availability,
    ) {}

    /**
     * @return array<string, array{row: NightlyCheck, ran: bool}> check => its row, and whether this call ran it
     */
    public function run(string $outletId, string $date, bool $force = false): array
    {
        $shop = Shop::query()->where('ulid', $outletId)->first();
        $tenantId = $shop?->tenant?->ulid ?? DB::table('khana_outlets')->where('id', $outletId)->value('tenant_id');
        $key = fn (string $check) => ['outlet_id' => $outletId, 'business_date' => $date, 'check' => $check];
        $results = [];

        foreach (self::ORDER as $check) {
            $row = NightlyCheck::query()->where($key($check))->first();

            if ($row !== null && $row->isFinal() && in_array($check, self::ONCE_PER_DAY, true) && ! $force) {
                $results[$check] = ['row' => $row, 'ran' => false];

                continue;
            }

            $finding = $this->check($check, $outletId, $shop);

            if ($finding->isProblem()) {
                Log::warning('A nightly check needs a look.', $key($check) + ['detail' => $finding->detail]);
            }

            $results[$check] = ['ran' => true, 'row' => NightlyCheck::query()->updateOrCreate($key($check), [
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
     * One check, which is never allowed to stop the others: whatever it throws
     * is reported, and written down as a problem to try again.
     */
    private function check(string $check, string $outletId, ?Shop $shop): Finding
    {
        try {
            return match ($check) {
                // Reference reconciles CRM's copy of the business from Accounts;
                // it needs the outlet's id, not a CRM shop row.
                NightlyCheck::REFERENCE => $this->reference->run($outletId),
                // Availability reconciles this outlet's sold-out against ERP;
                // without a CRM shop there is nothing of CRM's to put right.
                NightlyCheck::AVAILABILITY => $shop === null
                    ? Finding::skipped('CRM has no shop for this outlet.')
                    : $this->availability->run($shop),
            };
        } catch (Throwable $e) {
            report($e);

            return Finding::problem(0, 0, 'The check stopped before it finished, and has been reported. CRM tries again every hour.');
        }
    }
}
