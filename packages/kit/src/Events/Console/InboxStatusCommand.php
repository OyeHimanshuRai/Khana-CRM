<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Khana\Kit\Events\Inbox\InboxEvent;

final class InboxStatusCommand extends Command
{
    protected $signature = 'inbox:status';

    protected $description = 'Show received events by status, and every failure';

    public function handle(): int
    {
        $counts = InboxEvent::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        foreach ([InboxEvent::PENDING, InboxEvent::APPLIED, InboxEvent::IGNORED, InboxEvent::FAILED, InboxEvent::SKIPPED] as $status) {
            $this->components->twoColumnDetail(ucfirst($status), (string) ($counts[$status] ?? 0));
        }

        $failed = InboxEvent::query()->where('status', InboxEvent::FAILED)->orderBy('id')->limit(50)->get();

        if ($failed->isNotEmpty()) {
            $this->newLine();
            $this->table(
                ['Id', 'Event', 'Type', 'Stream', 'Error'],
                $failed->map(fn (InboxEvent $e) => [$e->id, $e->event_id, $e->type.' v'.$e->version, $e->stream, mb_strimwidth((string) $e->error, 0, 80, '...')])->all(),
            );
        }

        return self::SUCCESS;
    }
}
