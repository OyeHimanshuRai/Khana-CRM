<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Khana\Kit\Events\Envelope;
use Khana\Kit\Events\Inbox\Handlers;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Inbox\RetryLater;
use Khana\Kit\Events\Signature;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tests\Concerns\SeedsReference;
use Khana\Kit\Tests\TestCase;
use RuntimeException;

class InboxTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReference;

    private const SECRET = 'crm-to-erp-secret';

    private const TENANT = '01M3D669MZPZP9EP7ND2XFY1W2';

    private const OUTLET_A = '01M3D669NC4JCJSRMWG4WWDRRT';

    private const OUTLET_B = '01M3D669NWA5VJ3CD5YEJ3NZZC';

    /** @var list<string> */
    private array $applied = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'khana.service' => 'erp',
            'khana.events.senders' => ['crm' => self::SECRET],
        ]);

        // Both outlets belong to the sample business and own ERP, as Accounts
        // would have said before any of their sales arrived.
        $this->seedOutlet(self::TENANT, self::OUTLET_A);
        $this->seedOutlet(self::TENANT, self::OUTLET_B);

        $this->app->make(Handlers::class)->on('crm.sale.cancelled', 1, function (Envelope $event) {
            if (($event->data['reason'] ?? '') === 'explode') {
                throw new RuntimeException('Handler failed.');
            }

            if (($event->data['reason'] ?? '') === 'too early') {
                throw new RetryLater('Outlet not known yet.');
            }

            DB::table('khana_outbox_cursors')->insert(['subscriber' => 'side-effect-'.$event->seq, 'last_seq' => 0]);

            $this->applied[] = $event->id;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellation(int $seq, string $outletId = self::OUTLET_A, string $reason = 'Wrong table'): array
    {
        $event = self::fixture('crm.sale.cancelled');
        $event['id'] = Ulid::generate();
        $event['seq'] = $seq;
        $event['outlet_id'] = $outletId;
        $event['data']['reason'] = $reason;

        return $event;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function sendBatch(array $events, string $source = 'crm', string $secret = self::SECRET, ?int $time = null, ?string $body = null): TestResponse
    {
        $body ??= json_encode(['events' => $events], JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/internal/events', [], [], [], [
            'HTTP_X_KHANA_SOURCE' => $source,
            'HTTP_X_KHANA_SIGNATURE' => Signature::sign($body, $secret, $time),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    public function test_a_signed_batch_is_stored_and_applied(): void
    {
        $events = [$this->cancellation(1), $this->cancellation(2)];

        $this->sendBatch($events)->assertOk()->assertExactJson(['received' => 2, 'stored' => 2, 'duplicates' => 0]);

        $this->assertSame([$events[0]['id'], $events[1]['id']], $this->applied);
        $this->assertSame(2, InboxEvent::query()->where('status', InboxEvent::APPLIED)->count());
    }

    public function test_the_same_batch_twice_is_stored_and_applied_once(): void
    {
        $events = [$this->cancellation(1)];

        $this->sendBatch($events)->assertOk();
        $this->sendBatch($events)->assertOk()->assertJson(['stored' => 0, 'duplicates' => 1]);

        $this->assertSame(1, InboxEvent::query()->count());
        $this->assertCount(1, $this->applied);
    }

    public function test_an_unknown_sender_is_refused(): void
    {
        $this->sendBatch([$this->cancellation(1)], source: 'pos')->assertStatus(401);
        $this->assertSame(0, InboxEvent::query()->count());
    }

    public function test_a_wrong_secret_is_refused(): void
    {
        $this->sendBatch([$this->cancellation(1)], secret: 'guess')->assertStatus(401);
    }

    public function test_a_stale_signature_is_refused(): void
    {
        $this->sendBatch([$this->cancellation(1)], time: time() - 301)->assertStatus(401);
    }

    public function test_a_body_that_is_not_a_batch_is_refused(): void
    {
        $this->sendBatch([], body: '{"event":{}}')->assertStatus(400);
    }

    public function test_a_malformed_envelope_refuses_the_whole_batch(): void
    {
        $bad = $this->cancellation(2);
        $bad['id'] = 'nope';

        $this->sendBatch([$this->cancellation(1), $bad])->assertStatus(422);
        $this->assertSame(0, InboxEvent::query()->count());
    }

    public function test_a_sender_cannot_speak_for_another_service(): void
    {
        $event = $this->cancellation(1);
        $event['source'] = 'accounts';

        $this->sendBatch([$event])->assertStatus(422);
    }

    public function test_an_event_with_no_handler_here_is_kept_and_ignored(): void
    {
        $event = self::fixture('crm.menu.item_archived');

        $this->sendBatch([$event])->assertOk();

        $this->assertSame(InboxEvent::IGNORED, InboxEvent::query()->sole()->status);
    }

    public function test_a_failure_pauses_only_its_own_outlet(): void
    {
        $this->sendBatch([
            $this->cancellation(1, self::OUTLET_A, 'explode'),
            $this->cancellation(2, self::OUTLET_A),
            $this->cancellation(3, self::OUTLET_B),
        ])->assertOk();

        $statuses = InboxEvent::query()->orderBy('seq')->pluck('status', 'seq')->all();

        $this->assertSame([1 => 'failed', 2 => 'pending', 3 => 'applied'], $statuses);
        $this->assertStringContainsString('Handler failed.', (string) InboxEvent::query()->where('seq', 1)->value('error'));
    }

    public function test_a_handler_that_fails_leaves_no_half_applied_effect(): void
    {
        $this->app->make(Handlers::class)->on('crm.sale.returned', 1, function () {
            DB::table('khana_outbox_cursors')->insert(['subscriber' => 'half-done', 'last_seq' => 0]);

            throw new RuntimeException('Failed after writing.');
        });

        $this->sendBatch([self::fixture('crm.sale.returned')])->assertOk();

        $this->assertFalse(DB::table('khana_outbox_cursors')->where('subscriber', 'half-done')->exists());
    }

    public function test_a_failed_event_can_be_retried_once_fixed(): void
    {
        $this->sendBatch([
            $this->cancellation(1, self::OUTLET_A, 'explode'),
            $this->cancellation(2, self::OUTLET_A),
        ])->assertOk();

        InboxEvent::query()->where('seq', 1)->update(['data' => json_encode((object) ['sale_id' => '01M3D669SW4FC5J17YC290RRDV', 'reason' => 'fixed'])]);

        $this->artisan('inbox:retry', ['--all' => true])->assertExitCode(0);

        $this->assertSame(2, InboxEvent::query()->where('status', InboxEvent::APPLIED)->count());
    }

    public function test_skipping_needs_a_reason_and_lets_the_outlet_carry_on(): void
    {
        $this->sendBatch([
            $this->cancellation(1, self::OUTLET_A, 'explode'),
            $this->cancellation(2, self::OUTLET_A),
        ])->assertOk();

        $failed = InboxEvent::query()->where('seq', 1)->sole();

        $this->artisan('inbox:skip', ['event' => $failed->event_id])->assertExitCode(1);
        $this->artisan('inbox:skip', ['event' => $failed->event_id, '--reason' => 'Sale was voided by hand'])->assertExitCode(0);

        $this->assertSame(InboxEvent::SKIPPED, $failed->fresh()->status);
        $this->assertSame('Sale was voided by hand', $failed->fresh()->skip_reason);
        $this->assertSame(InboxEvent::APPLIED, InboxEvent::query()->where('seq', 2)->value('status'));
    }

    public function test_an_event_that_arrives_too_early_waits_and_is_applied_later(): void
    {
        $this->sendBatch([
            $this->cancellation(1, self::OUTLET_A, 'too early'),
            $this->cancellation(2, self::OUTLET_A),
        ])->assertOk();

        $early = InboxEvent::query()->where('seq', 1)->sole();

        $this->assertSame(InboxEvent::PENDING, $early->status);
        $this->assertSame(1, $early->attempts);
        $this->assertSame(InboxEvent::PENDING, InboxEvent::query()->where('seq', 2)->value('status'));

        InboxEvent::query()->whereKey($early->id)->update(['data' => json_encode((object) ['sale_id' => '01M3D669SW4FC5J17YC290RRDV', 'reason' => 'in time'])]);

        $this->artisan('inbox:work')->assertExitCode(0);

        $this->assertSame(2, InboxEvent::query()->where('status', InboxEvent::APPLIED)->count());
    }

    public function test_the_diagnostic_ping_is_applied_by_the_kit_itself(): void
    {
        $this->sendBatch([self::fixture('system.ping')])->assertOk();

        $this->assertSame(InboxEvent::APPLIED, InboxEvent::query()->sole()->status);
    }
}
