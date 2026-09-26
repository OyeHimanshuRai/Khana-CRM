<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Khana\Kit\Contracts\ContractViolation;
use Khana\Kit\Events\Delivery\DeliveryFilter;
use Khana\Kit\Events\Outbox;
use Khana\Kit\Events\OutboxCursor;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Events\Sequencer;
use Khana\Kit\Events\Signature;
use Khana\Kit\Tests\TestCase;
use RuntimeException;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://erp.test/internal/events';

    private const SECRET = 'crm-to-erp-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['khana.events.subscribers' => [
            'erp' => ['url' => self::URL, 'secret' => self::SECRET, 'types' => ['crm.*', 'system.*']],
        ]]);
    }

    private function outbox(): Outbox
    {
        return $this->app->make(Outbox::class);
    }

    private function recordCancellation(string $reason = 'Wrong table'): OutboxEvent
    {
        $sample = self::fixture('crm.sale.cancelled');

        return $this->outbox()->record(
            'crm.sale.cancelled',
            ['sale_id' => $sample['data']['sale_id'], 'reason' => $reason],
            $sample['tenant_id'],
            $sample['outlet_id'],
        );
    }

    public function test_an_event_is_sent_signed_in_the_contract_envelope(): void
    {
        Http::fake([self::URL => Http::response(['stored' => 1])]);

        $event = DB::transaction(fn () => $this->recordCancellation());

        Http::assertSent(function (Request $request) use ($event) {
            $body = $request->body();
            $events = json_decode($body, true)['events'];

            return $request->url() === self::URL
                && $request->header(Signature::SOURCE_HEADER)[0] === 'crm'
                && Signature::verify($request->header(Signature::HEADER)[0], $body, self::SECRET)
                && count($events) === 1
                && $events[0]['id'] === $event->event_id
                && $events[0]['seq'] === 1
                && $events[0]['source'] === 'crm'
                && $events[0]['type'] === 'crm.sale.cancelled';
        });

        $this->assertSame(1, OutboxCursor::for('erp')->last_seq);
    }

    public function test_a_rolled_back_change_leaves_no_event_and_sends_nothing(): void
    {
        Http::fake();

        try {
            DB::transaction(function () {
                $this->recordCancellation();

                throw new RuntimeException('The change failed.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, OutboxEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_payload_that_breaks_its_contract_is_refused_before_it_is_stored(): void
    {
        $this->expectException(ContractViolation::class);

        try {
            $this->outbox()->record('crm.sale.cancelled', ['sale_id' => 'not-a-ulid']);
        } finally {
            $this->assertSame(0, OutboxEvent::query()->count());
        }
    }

    public function test_validation_can_be_switched_off_where_schemas_are_not_shipped(): void
    {
        Http::fake();
        config(['khana.events.validate' => false]);

        $this->outbox()->record('crm.sale.cancelled', ['sale_id' => 'not-a-ulid']);

        $this->assertSame(1, OutboxEvent::query()->count());
    }

    public function test_a_service_must_know_its_own_name(): void
    {
        config(['khana.service' => null]);

        $this->expectException(RuntimeException::class);

        $this->recordCancellation();
    }

    public function test_seq_is_given_once_in_commit_order(): void
    {
        // Hold the delivery job back, so the sequencer is only run by hand.
        Queue::fake();

        $first = $this->recordCancellation('one');
        $second = $this->recordCancellation('two');

        $this->assertNull($first->fresh()->seq);

        $sequencer = $this->app->make(Sequencer::class);

        $this->assertSame(2, $sequencer->run());
        $this->assertSame(0, $sequencer->run());
        $this->assertSame([1, 2], [$first->fresh()->seq, $second->fresh()->seq]);

        $third = $this->recordCancellation('three');
        $sequencer->run();

        $this->assertSame(3, $third->fresh()->seq);
    }

    public function test_a_receiver_that_is_down_keeps_its_place_and_backs_off(): void
    {
        $status = 503;
        Http::fake([self::URL => function () use (&$status) {
            return Http::response($status === 503 ? 'down' : ['stored' => 1], $status);
        }]);

        $this->recordCancellation();

        $cursor = OutboxCursor::for('erp');
        $this->assertSame(0, $cursor->last_seq);
        $this->assertSame(1, $cursor->attempts);
        $this->assertStringContainsString('503', (string) $cursor->last_error);
        $this->assertTrue($cursor->isWaiting());

        // While backing off, the sweep does not hammer it...
        $this->artisan('outbox:deliver')->assertExitCode(0);
        Http::assertSentCount(1);

        // ...and once it is back, everything waiting goes, in order.
        $status = 200;
        $this->artisan('outbox:deliver', ['--force' => true])->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertSame(1, OutboxCursor::for('erp')->last_seq);
        $this->assertSame(0, OutboxCursor::for('erp')->attempts);
    }

    public function test_events_a_receiver_does_not_subscribe_to_are_stepped_over(): void
    {
        Http::fake();
        config(['khana.events.subscribers.erp.types' => ['system.*']]);

        $this->recordCancellation();

        Http::assertNothingSent();
        $this->assertSame(1, OutboxCursor::for('erp')->last_seq);
    }

    public function test_a_filter_can_hold_back_an_outlet(): void
    {
        Http::fake();

        $this->app->instance(RejectEverything::class, new RejectEverything());
        config(['khana.events.subscribers.erp.filter' => RejectEverything::class]);

        $this->recordCancellation();

        Http::assertNothingSent();
    }

    public function test_big_backlogs_go_in_ordered_batches(): void
    {
        Http::fake([self::URL => Http::response(['stored' => 1])]);
        config(['khana.events.batch_size' => 2]);

        DB::transaction(function () {
            foreach (range(1, 5) as $n) {
                $this->recordCancellation("reason {$n}");
            }
        });

        $seqs = [];

        Http::assertSentCount(3);
        Http::assertSent(function (Request $request) use (&$seqs) {
            foreach (json_decode($request->body(), true)['events'] as $event) {
                $seqs[] = $event['seq'];
            }

            return true;
        });

        $this->assertSame([1, 2, 3, 4, 5], $seqs);
    }

    public function test_a_replay_sends_again_from_the_chosen_seq(): void
    {
        Http::fake([self::URL => Http::response(['stored' => 1])]);

        DB::transaction(function () {
            foreach (range(1, 3) as $n) {
                $this->recordCancellation("reason {$n}");
            }
        });

        $this->artisan('outbox:replay', ['subscriber' => 'erp', '--from' => 2])->assertExitCode(0);

        $replayed = [];

        Http::assertSent(function (Request $request) use (&$replayed) {
            $replayed[] = array_column(json_decode($request->body(), true)['events'], 'seq');

            return true;
        });

        $this->assertSame([[1, 2, 3], [2, 3]], $replayed);
        $this->assertSame(3, OutboxCursor::for('erp')->last_seq);
    }

    public function test_pruning_never_drops_an_event_a_receiver_has_not_had(): void
    {
        Http::fake([self::URL => Http::response('down', 503)]);

        $unsent = $this->recordCancellation();
        OutboxEvent::query()->update(['created_at' => now()->subDays(120)]);
        $this->app->make(Sequencer::class)->run();

        $this->artisan('outbox:prune')->assertExitCode(0);
        $this->assertNotNull($unsent->fresh());

        OutboxCursor::for('erp')->advanceTo(1);

        $this->artisan('outbox:prune')->assertExitCode(0);
        $this->assertNull($unsent->fresh());
    }
}

final class RejectEverything implements DeliveryFilter
{
    public function accepts(string $subscriber, OutboxEvent $event): bool
    {
        return false;
    }
}
