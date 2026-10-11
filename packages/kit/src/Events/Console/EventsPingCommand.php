<?php

namespace Khana\Kit\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Events\Delivery\Deliverer;
use Khana\Kit\Events\Delivery\DeliveryReport;
use Khana\Kit\Events\Outbox;
use Khana\Kit\Support\Ulid;

/**
 * Record a system.ping and send it straight away - the end-to-end check
 * that two services can hear each other, signatures and all.
 */
final class EventsPingCommand extends Command
{
    protected $signature = 'events:ping {--note= : Text to carry} {--tenant= : Tenant ULID} {--outlet= : Outlet ULID}';

    protected $description = 'Send a signed test event to every subscriber';

    public function handle(Outbox $outbox, Deliverer $deliverer): int
    {
        foreach (['tenant', 'outlet'] as $key) {
            $value = $this->option($key);

            if ($value !== null && ! Ulid::isValid($value)) {
                $this->components->error("--{$key} must be a ULID.");

                return self::FAILURE;
            }
        }

        $event = DB::transaction(fn () => $outbox->record('system.ping', [
            'note' => $this->option('note') ?? 'ping from '.Outbox::source(),
        ], $this->option('tenant'), $this->option('outlet')));

        $this->components->twoColumnDetail('Recorded', $event->event_id);

        $failed = false;

        foreach ($deliverer->run(force: true) as $name => $report) {
            $failed = $failed || $report->outcome === DeliveryReport::FAILED;

            $this->components->twoColumnDetail(
                $name,
                $report->outcome === DeliveryReport::FAILED ? 'failed: '.$report->error : "{$report->outcome}, up to seq {$report->lastSeq}",
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
