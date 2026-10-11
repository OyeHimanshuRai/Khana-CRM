<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Outbox;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Support\Ulid;
use Khana\Kit\Tenancy\OnlyEntitledOutlets;
use RuntimeException;
use Tests\TestCase;

/**
 * erp:watch - the alarm on the event plumbing: an event CRM should have had
 * ten minutes ago, or one ERP received and could not apply.
 */
class EventWatchTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $suite;

    /** @var array{tenant: string, outlet: string} */
    private array $alone;

    private bool $crmDown = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suite = $this->business('Spice Garden', ['erp' => 'active', 'crm' => 'active']);
        $this->alone = $this->business('Dosa Corner', ['erp' => 'active']);

        config(['khana.events.subscribers.crm' => [
            'url' => 'https://crm.example.test/internal/events',
            'secret' => 'test-secret-erp-to-crm',
            'types' => ['erp.*', 'system.*'],
            'filter' => OnlyEntitledOutlets::class,
        ]]);

        Http::preventStrayRequests();
        Http::fake(['crm.example.test/internal/events' => fn () => $this->crmDown
            ? Http::response(['message' => 'Down for maintenance.'], 503)
            : Http::response(['received' => 1])]);

        Log::spy();
        Exceptions::fake();
    }

    /** A sold-out signal for an outlet, recorded the way Availability records one. */
    private function signal(array $at): void
    {
        app(Outbox::class)->record('erp.availability.changed', [
            'item_id' => Ulid::generate(), 'variant_id' => null, 'available' => false, 'reason' => 'stock_out',
        ], $at['tenant'], $at['outlet']);
    }

    private function age(int $minutes): void
    {
        OutboxEvent::query()->update(['created_at' => now()->subMinutes($minutes)]);
    }

    public function test_an_event_crm_should_have_had_ten_minutes_ago_raises_the_alarm_once_an_hour(): void
    {
        // CRM is down: delivery was tried at once, and failed.
        $this->signal($this->suite);
        Http::assertSentCount(1);

        // Five minutes is the plumbing doing its job.
        $this->age(5);
        $this->artisan('erp:watch')->assertSuccessful();
        Log::shouldNotHaveReceived('critical');

        $this->age(11);
        $this->artisan('erp:watch')->expectsOutputToContain('have waited over 10 minutes to reach crm')->assertFailed();

        Log::shouldHaveReceived('critical')->once()->withArgs(
            fn (string $message) => str_starts_with($message, '1 ERP event(s) have waited over 10 minutes to reach crm')
                && str_contains($message, 'crm answered 503'),
        );
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'to reach crm'));

        // Still waiting ten minutes on: said on the console, but nobody is woken twice in the hour.
        $this->travel(10)->minutes();
        $this->artisan('erp:watch')->assertFailed();
        Log::shouldHaveReceived('critical')->once();

        $this->travel(51)->minutes();
        $this->artisan('erp:watch')->assertFailed();
        Log::shouldHaveReceived('critical')->twice();

        // CRM is back and has had everything: the watch goes quiet.
        $this->crmDown = false;
        $this->artisan('outbox:deliver')->assertSuccessful();
        $this->artisan('erp:watch')->assertSuccessful();
    }

    public function test_only_what_crm_actually_wants_counts_as_waiting(): void
    {
        // A signal about an outlet with no CRM is stepped over by the sender: nobody's delay.
        $this->signal($this->alone);
        $this->signal($this->suite);
        $this->signal($this->suite);
        $this->age(30);

        $this->artisan('erp:watch')->expectsOutputToContain('2 ERP event(s) have waited')->assertFailed();
    }

    public function test_events_not_yet_numbered_count_too_for_a_sender_that_is_not_running(): void
    {
        OutboxEvent::query()->create([
            'event_id' => Ulid::generate(), 'type' => 'erp.availability.changed', 'version' => 1,
            'tenant_id' => $this->suite['tenant'], 'outlet_id' => $this->suite['outlet'], 'occurred_at' => now(),
            'data' => ['item_id' => Ulid::generate(), 'variant_id' => null, 'available' => true, 'reason' => 'restocked'],
        ]);
        $this->age(20);

        $this->assertNull(OutboxEvent::query()->value('seq'));
        $this->artisan('erp:watch')->expectsOutputToContain('1 ERP event(s) have waited')->assertFailed();
    }

    public function test_an_event_received_that_could_not_be_applied_raises_the_alarm(): void
    {
        InboxEvent::query()->create([
            'event_id' => Ulid::generate(), 'source' => 'crm', 'seq' => 7, 'type' => 'crm.sale.recorded', 'version' => 1,
            'tenant_id' => $this->suite['tenant'], 'outlet_id' => $this->suite['outlet'],
            'stream' => InboxEvent::streamFor('crm', $this->suite['tenant'], $this->suite['outlet']),
            'occurred_at' => now(), 'data' => ['sale_id' => Ulid::generate()], 'status' => InboxEvent::FAILED,
            'attempts' => 1, 'error' => 'ErrorException: Undefined array key "lines"',
        ]);

        $this->artisan('erp:watch')->expectsOutputToContain('could not be applied')->assertFailed();

        Log::shouldHaveReceived('critical')->once()->withArgs(
            fn (string $message) => str_starts_with($message, '1 event(s) ERP received could not be applied, and 1 outlet(s) are waiting behind them.')
                && str_contains($message, 'crm.sale.recorded') && str_contains($message, 'Undefined array key "lines"'),
        );

        $this->artisan('erp:watch')->assertFailed();
        Log::shouldHaveReceived('critical')->once();
    }

    public function test_a_quiet_plumbing_raises_nothing(): void
    {
        $this->crmDown = false;
        $this->signal($this->suite);
        $this->age(60);

        $this->artisan('erp:watch')->assertSuccessful();

        Log::shouldNotHaveReceived('critical');
        Exceptions::assertNothingReported();
    }
}
