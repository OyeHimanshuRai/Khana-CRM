<?php

namespace App\Console\Commands;

use App\Services\SubscriptionNoticeService as Notice;
use Illuminate\Console\Command;

/**
 * Email owners whose trial or plan is about to end, or just has.
 *
 * Scheduled for 09:00 India time (routes/console.php). Safe to run by hand
 * and safe to run twice: every notice is claimed through a unique index
 * before it is sent. See SubscriptionNoticeService.
 */
class SendSubscriptionNotices extends Command
{
    protected $signature = 'subscriptions:remind';

    protected $description = 'Email owners whose trial or plan ends in 2 days, tomorrow, today, or has ended';

    private const LABELS = [
        Notice::ENDING_2 => 'ends in 2 days',
        Notice::ENDING_1 => 'ends tomorrow',
        Notice::ENDING_0 => 'ends today',
        Notice::ENDED => 'has ended',
    ];

    public function handle(Notice $notices): int
    {
        $sent = $notices->run();

        if ($sent->isEmpty()) {
            $this->info('No notices due.');

            return self::SUCCESS;
        }

        $this->table(
            ['Business', 'Plan', 'Notice', 'Emails'],
            $sent->map(fn (array $row) => [
                $row['subscription']->shop?->name ?? $row['subscription']->tenant?->name ?? '—',
                $row['subscription']->plan?->name ?? '—',
                self::LABELS[$row['kind']] ?? $row['kind'],
                $row['recipients'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
