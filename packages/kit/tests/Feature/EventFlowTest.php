<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\OutboxEvent;
use Khana\Kit\Tests\TestCase;

/**
 * Gate G0 in one process: a signed event goes from the sender's outbox to
 * the receiver's inbox, is stored once, and a replay changes nothing.
 *
 * The HTTP fake hands each outgoing request to this same application's
 * /internal/events route, so both halves run for real: signing, the wire
 * format, verification, storage and application.
 */
class EventFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'crm-to-erp-secret';

    /** @var list<array<string, mixed>> */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'khana.service' => 'crm',
            'khana.events.subscribers' => [
                'erp' => ['url' => 'http://localhost/internal/events', 'secret' => self::SECRET, 'types' => ['crm.*', 'system.*']],
            ],
            'khana.events.senders' => ['crm' => self::SECRET],
        ]);

        Http::fake(function (Request $request) {
            $response = $this->call('POST', '/internal/events', [], [], [], [
                'HTTP_X_KHANA_SOURCE' => $request->header('X-Khana-Source')[0] ?? '',
                'HTTP_X_KHANA_SIGNATURE' => $request->header('X-Khana-Signature')[0] ?? '',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $request->body());

            $this->answers[] = json_decode((string) $response->getContent(), true);

            return Http::response($response->getContent(), $response->getStatusCode());
        });
    }

    public function test_a_signed_ping_is_delivered_stored_once_and_replays_cleanly(): void
    {
        $this->artisan('events:ping', ['--note' => 'gate G0'])->assertExitCode(0);

        $sent = OutboxEvent::query()->sole();
        $received = InboxEvent::query()->sole();

        $this->assertSame($sent->event_id, $received->event_id);
        $this->assertSame('crm', $received->source);
        $this->assertSame(1, $received->seq);
        $this->assertSame(InboxEvent::APPLIED, $received->status);
        $this->assertSame('gate G0', $received->data['note']);

        $this->artisan('outbox:replay', ['subscriber' => 'erp', '--from' => 1])->assertExitCode(0);

        $this->assertSame(1, InboxEvent::query()->count());
        $this->assertSame(['received' => 1, 'stored' => 0, 'duplicates' => 1], end($this->answers));
        $this->assertSame($received->applied_at?->toIso8601String(), InboxEvent::query()->sole()->applied_at?->toIso8601String());
    }
}
