<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Console\Command;

/**
 * Send whatever campaigns are due (SRS 15, 21).
 *
 * ---------------------------------------------------------------------------
 * A batch per run, not a campaign per run
 * ---------------------------------------------------------------------------
 *
 * Four hundred HTTP requests in one command is a command that gets killed at
 * about the fortieth by whatever watchdog is running, and then nobody can say
 * which ones went. So each run sends a batch and leaves the campaign in
 * `sending`; the next tick picks it up.
 *
 * The recipient rows are what make that safe - see CampaignService. A run
 * interrupted at any point resumes exactly where it stopped, because "who has
 * already had this" is written down rather than counted.
 */
class SendCampaigns extends Command
{
    protected $signature = 'campaigns:send
                            {--batch= : How many messages to send per campaign this run}
                            {--campaign= : Only this campaign id}';

    protected $description = 'Send the next batch of any campaign that is due';

    public function handle(CampaignService $campaigns): int
    {
        $batch = (int) ($this->option('batch') ?: CampaignService::BATCH);

        $due = $campaigns->due();

        if ($this->option('campaign')) {
            $due = $due->where('id', (int) $this->option('campaign'));
        }

        if ($due->isEmpty()) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($due as $campaign) {
            $sent = $campaigns->run($campaign, $batch);
            $total += $sent;

            $fresh = $campaign->fresh();

            $this->line(sprintf(
                '%s — %d sent this run, %d of %d done%s',
                $campaign->name,
                $sent,
                $fresh->sent_count + $fresh->failed_count,
                $fresh->audience_count,
                $fresh->status === Campaign::SENT ? ' (finished)' : '',
            ));

            if ($fresh->failed_count > 0) {
                /*
                 | Worth saying out loud. A campaign with failures is usually
                 | a provider problem rather than a per-number one, and the
                 | difference between three failures and three hundred is the
                 | difference between bad numbers and a broken account.
                 */
                $this->warn(sprintf('  %d failed — check the recipient list.', $fresh->failed_count));
            }
        }

        $this->info($total.' message(s) attempted.');

        return self::SUCCESS;
    }
}
