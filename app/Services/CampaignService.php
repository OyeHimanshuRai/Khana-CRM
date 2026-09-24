<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyTransaction;
use App\Services\Sms\SmsManager;
use App\Services\Whatsapp\WhatsAppManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sending one message to a lot of people (SRS 15, 21).
 *
 * ---------------------------------------------------------------------------
 * The audience is frozen before anything is sent
 * ---------------------------------------------------------------------------
 *
 * See the migration for why. In short: a campaign is a thing that happened,
 * not a thing that was attempted, and resolving the audience at send time
 * makes it impossible to resume, impossible to explain and quietly unstable
 * while it runs.
 *
 * ---------------------------------------------------------------------------
 * Sending is slow on purpose
 * ---------------------------------------------------------------------------
 *
 * A batch at a time, from a scheduled command, with the campaign left in
 * `sending` between runs. Firing four hundred HTTP requests inside one web
 * request would time out at about the fortieth and leave nobody able to say
 * which ones went.
 */
class CampaignService
{
    /** Messages per run. Small enough that a run always finishes. */
    public const BATCH = 100;

    public function __construct(
        private readonly SmsManager $sms,
        private readonly WhatsAppManager $whatsapp,
    ) {}

    /* --------------------------------------------------------- the audience */

    /**
     * Who a segment describes, without writing anything.
     *
     * Used by the form to say "this will go to 214 people" before somebody
     * commits to it - which is the one number that stops a campaign being
     * sent to the wrong list.
     *
     * @param  array<string, mixed>  $segment
     * @return Builder<Customer>
     */
    public function audienceQuery(array $segment): Builder
    {
        $query = Customer::query()
            ->where('is_active', true)
            /*
             | No number, no message. This is the only filter that is not
             | optional: a campaign row for somebody unreachable is a failure
             | recorded for a thing that was never possible.
             */
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '');

        /* --------------------------------------------------- when they came */

        if (filled($segment['visited_within_days'] ?? null)) {
            $since = now()->subDays((int) $segment['visited_within_days']);

            $query->whereExists(fn ($q) => $this->billsFor($q, $since, null));
        }

        if (filled($segment['not_visited_for_days'] ?? null)) {
            $before = now()->subDays((int) $segment['not_visited_for_days']);

            /*
             | Lapsed regulars: they have been in at some point, and not
             | since. Both halves matter - without the first, the segment is
             | every customer record ever created, including the ones who
             | never came at all.
             */
            $query
                ->whereExists(fn ($q) => $this->billsFor($q, null, null))
                ->whereNotExists(fn ($q) => $this->billsFor($q, $before, null));
        }

        /* ------------------------------------------------- what they spent */

        if (filled($segment['min_spend'] ?? null)) {
            /*
             | The threshold is formatted into the SQL rather than bound, and
             | that is deliberate.
             |
             | Laravel binds a PHP float as PDO::PARAM_STR. MySQL coerces the
             | string back to a number and the comparison works; SQLite does
             | not - under its type ordering any TEXT value is greater than
             | any number, so `5000 >= '1000'` is FALSE. The filter would have
             | matched everybody in production and nobody in the tests, which
             | is the worst way round.
             |
             | sprintf('%.2f') is injection-safe by construction: whatever
             | arrives, what reaches the SQL is a number with two decimal
             | places and nothing else.
             */
            $query->whereRaw(
                sprintf(
                    '(select coalesce(sum(grand_total), 0) from invoices
                        where invoices.customer_id = customers.id
                          and invoices.status not in (?, ?)) >= %.2F',
                    (float) $segment['min_spend'],
                ),
                ['draft', 'cancelled'],
            );
        }

        if (filled($segment['min_visits'] ?? null)) {
            $query->whereRaw(
                '(select count(*) from invoices
                    where invoices.customer_id = customers.id
                      and invoices.status not in (?, ?)) >= ?',
                ['draft', 'cancelled', (int) $segment['min_visits']],
            );
        }

        /* --------------------------------------------------------- loyalty */

        if (! empty($segment['has_points'])) {
            $query->whereIn('id', LoyaltyTransaction::query()
                ->selectRaw('customer_id')
                ->groupBy('customer_id')
                ->havingRaw('SUM(points) > 0'));
        }

        return $query;
    }

    /**
     * The invoices sub-query, narrowed by date.
     *
     * Shared so "came since" and "has not come since" cannot drift apart -
     * two nearly-identical inline queries is how a lapsed-customer campaign
     * ends up going to people who were in yesterday.
     */
    private function billsFor($query, ?Carbon $from, ?Carbon $to): void
    {
        $query->selectRaw('1')
            ->from('invoices')
            ->whereColumn('invoices.customer_id', 'customers.id')
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->when($from, fn ($q) => $q->where('invoices.invoiced_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('invoices.invoiced_at', '<=', $to));
    }

    /** How many people a segment reaches. */
    public function audienceCount(array $segment): int
    {
        return $this->audienceQuery($segment)->count();
    }

    /* ---------------------------------------------------------- the send */

    /**
     * Freeze the audience and queue it.
     *
     * @throws RuntimeException
     */
    public function schedule(Campaign $campaign, ?Carbon $at = null): Campaign
    {
        if (! $campaign->isEditable()) {
            throw new RuntimeException('That campaign has already gone out.');
        }

        if (! $this->channelIsLive($campaign->channel)) {
            throw new RuntimeException(sprintf(
                'No %s provider is set up, so nothing would be sent. Configure one first.',
                $campaign->channelLabel(),
            ));
        }

        return DB::transaction(function () use ($campaign, $at) {
            // Re-resolved rather than re-used: a draft may have been sitting
            // for a week, and the audience it is sent to must be the one that
            // existed when somebody pressed send.
            $campaign->recipients()->delete();

            $frozen = 0;

            $this->audienceQuery($campaign->segment ?? [])
                ->select(['id', 'name', 'mobile'])
                ->chunkById(500, function (Collection $customers) use ($campaign, &$frozen) {
                    $rows = [];

                    foreach ($customers as $customer) {
                        $rows[] = [
                            'campaign_id' => $campaign->id,
                            'customer_id' => $customer->id,
                            'destination' => $customer->mobile,
                            'name' => $customer->name,
                            'status' => CampaignRecipient::PENDING,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    /*
                     | Ignoring duplicates rather than failing on them.
                     |
                     | Not for households - `customers` already carries a
                     | unique (shop_id, mobile) index, so two records cannot
                     | share a number. It is for re-queueing: a campaign sent
                     | back to draft and queued again must not blow up on the
                     | rows from last time, and the unique index is what makes
                     | that safe rather than merely likely.
                     */
                    $inserted = CampaignRecipient::query()->insertOrIgnore($rows);

                    $frozen += $inserted;
                });

            if ($frozen === 0) {
                throw new RuntimeException(
                    'That segment matches nobody with a mobile number. Widen it, or check the customer records.'
                );
            }

            $campaign->forceFill([
                'status' => Campaign::SCHEDULED,
                'scheduled_for' => $at,
                'audience_count' => $frozen,
                'sent_count' => 0,
                'failed_count' => 0,
            ])->save();

            return $campaign->fresh();
        });
    }

    /**
     * Send one batch of whatever is due.
     *
     * Returns how many were attempted, so the command can say something and
     * the scheduler can keep calling until there is nothing left.
     */
    public function run(Campaign $campaign, int $limit = self::BATCH): int
    {
        if ($campaign->status === Campaign::CANCELLED) {
            return 0;
        }

        if (! $this->channelIsLive($campaign->channel)) {
            // Left scheduled rather than failed: the provider being switched
            // off is a thing somebody fixes, not a reason to burn the
            // campaign.
            return 0;
        }

        $campaign->forceFill([
            'status' => Campaign::SENDING,
            'started_at' => $campaign->started_at ?? now(),
        ])->save();

        $batch = $campaign->recipients()->pending()->limit($limit)->get();

        foreach ($batch as $recipient) {
            $this->deliver($campaign, $recipient);
        }

        $this->retally($campaign);

        return $batch->count();
    }

    private function deliver(Campaign $campaign, CampaignRecipient $recipient): void
    {
        $message = $campaign->renderFor($recipient->name);

        try {
            $ok = $campaign->channel === 'whatsapp'
                ? $this->whatsapp->send(
                    $recipient->destination,
                    $campaign->template ?: 'reminder',
                    [$recipient->name ?: 'there'],
                    $message,
                )
                : $this->sms->gateway()->send($recipient->destination, $message);
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
        }

        $recipient->forceFill($ok
            ? ['status' => CampaignRecipient::SENT, 'sent_at' => now(), 'error' => null]
            : ['status' => CampaignRecipient::FAILED, 'error' => 'The provider refused the message']
        )->save();
    }

    /**
     * Recount from the rows, and close the campaign when nothing is left.
     *
     * Counted rather than incremented. An increment is wrong the moment a run
     * crashes between sending and saving, and the rows are the record anyway.
     */
    private function retally(Campaign $campaign): void
    {
        $counts = $campaign->recipients()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts[CampaignRecipient::PENDING] ?? 0);

        $campaign->forceFill([
            'sent_count' => (int) ($counts[CampaignRecipient::SENT] ?? 0),
            'failed_count' => (int) ($counts[CampaignRecipient::FAILED] ?? 0),
            'status' => $pending > 0 ? Campaign::SENDING : Campaign::SENT,
            'finished_at' => $pending > 0 ? null : now(),
        ])->save();
    }

    /** Stop a campaign that has not finished. */
    public function cancel(Campaign $campaign): Campaign
    {
        if ($campaign->status === Campaign::SENT) {
            throw new RuntimeException('That campaign has already gone out. It cannot be unsent.');
        }

        DB::transaction(function () use ($campaign) {
            // Anything not yet sent is marked skipped rather than deleted, so
            // the record still shows who was in the audience.
            $campaign->recipients()->pending()->update([
                'status' => CampaignRecipient::SKIPPED,
                'updated_at' => now(),
            ]);

            $campaign->forceFill([
                'status' => Campaign::CANCELLED,
                'finished_at' => now(),
            ])->save();
        });

        return $campaign->fresh();
    }

    /**
     * Every campaign the sender should pick up.
     *
     * @return Collection<int, Campaign>
     */
    public function due(): Collection
    {
        return Campaign::query()->withoutGlobalScopes()->due()->get();
    }

    private function channelIsLive(string $channel): bool
    {
        return $channel === 'whatsapp'
            ? $this->whatsapp->isLive()
            : $this->sms->isLive();
    }
}
