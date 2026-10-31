<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Shop;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\LoyaltyService;
use App\Services\Sms\SmsManager;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Sending one message to a lot of people (§15, §21).
 *
 * Two properties are worth more than the rest:
 *
 *   1. The audience is frozen into rows before anything is sent. Without
 *      that, a campaign interrupted half way sends twice to some people and
 *      never to others, and "why did Mrs Mehta get this" has no answer.
 *
 *   2. A segment means what it says. The lapsed-regulars rule in particular
 *      has two halves, and dropping either one sends a "we have missed you"
 *      message to somebody who was in yesterday.
 *
 * Nothing here reaches the internet.
 */
class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private FakeSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        Http::preventStrayRequests();

        $this->sms = new FakeSender();
        app(SmsManager::class)->swap($this->sms);

        $this->actingAs($this->staff([
            'crm.campaigns.view', 'crm.campaigns.create',
            'crm.campaigns.edit', 'crm.campaigns.approve', 'crm.campaigns.delete',
        ]));
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function service(): CampaignService
    {
        return app(CampaignService::class);
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'marketing@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Marketing',
                'email' => 'marketing@example.test',
                'password' => 'marketing-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function customer(string $name, ?string $mobile = '9876543210'): Customer
    {
        return Customer::create([
            'shop_id' => $this->shop()->id,
            'code' => 'C-'.uniqid(),
            'name' => $name,
            'mobile' => $mobile,
            'is_active' => true,
        ]);
    }

    /** A settled bill for a customer on a given day. */
    private function billed(Customer $customer, Carbon $when, float $total = 1000): Invoice
    {
        $invoice = Invoice::create([
            'shop_id' => $this->shop()->id,
            'customer_id' => $customer->id,
            'number' => 'INV-'.uniqid(),
            'channel' => Invoice::POS,
            'status' => 'issued',
            'invoiced_at' => $when,
        ]);

        // Money columns are deliberately not fillable - see the model.
        $invoice->forceFill([
            'subtotal' => $total,
            'grand_total' => $total,
            'cost_total' => 0,
            'due_total' => 0,
        ])->save();

        return $invoice;
    }

    /** @param array<string, mixed> $segment */
    private function campaign(array $segment = [], string $channel = 'sms'): Campaign
    {
        return Campaign::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Test campaign',
            'channel' => $channel,
            'body' => 'Hello {name}, we have missed you.',
            'segment' => $segment,
            'status' => Campaign::DRAFT,
        ]);
    }

    /* --------------------------------------------------------- segments */

    public function test_somebody_with_no_mobile_number_is_never_included(): void
    {
        $this->customer('Reachable', '9000000001');
        $this->customer('No phone', null);

        // A row for somebody unreachable is a failure recorded for a thing
        // that was never possible.
        $this->assertSame(1, $this->service()->audienceCount([]));
    }

    public function test_lapsed_regulars_excludes_people_who_never_came(): void
    {
        $regular = $this->customer('Lapsed regular', '9000000001');
        $this->billed($regular, now()->subDays(200));

        // Never billed at all: a customer record somebody typed in and
        // nothing more.
        $this->customer('Never came', '9000000002');

        $recent = $this->customer('Came yesterday', '9000000003');
        $this->billed($recent, now()->subDay());

        $audience = $this->service()->audienceQuery(['not_visited_for_days' => 90])->pluck('name');

        /*
         | Both halves of the rule matter. Without "has been in at some
         | point" this is every customer record ever created; without "and
         | not since" it is everybody.
         */
        $this->assertContains('Lapsed regular', $audience->all());
        $this->assertNotContains('Never came', $audience->all());
        $this->assertNotContains('Came yesterday', $audience->all());
    }

    public function test_recent_visitors_are_found_by_their_last_bill(): void
    {
        $recent = $this->customer('Recent', '9000000001');
        $this->billed($recent, now()->subDays(5));

        $old = $this->customer('Old', '9000000002');
        $this->billed($old, now()->subDays(200));

        $audience = $this->service()->audienceQuery(['visited_within_days' => 30])->pluck('name');

        $this->assertSame(['Recent'], $audience->all());
    }

    public function test_a_spend_threshold_counts_only_real_bills(): void
    {
        $big = $this->customer('Big spender', '9000000001');
        $this->billed($big, now()->subDays(2), 5000);

        $small = $this->customer('Small', '9000000002');
        $this->billed($small, now()->subDays(2), 200);

        $cancelled = $this->customer('Cancelled', '9000000003');
        $this->billed($cancelled, now()->subDays(2), 9000)
            ->forceFill(['status' => 'cancelled'])->save();

        $audience = $this->service()->audienceQuery(['min_spend' => 1000])->pluck('name');

        // A cancelled bill is not spending.
        $this->assertSame(['Big spender'], $audience->all());
    }

    public function test_loyalty_holders_can_be_targeted(): void
    {
        $holder = $this->customer('Holder', '9000000001');
        $this->customer('Nobody', '9000000002');

        app(LoyaltyService::class)->adjust($holder, 200, 'seeded for the test');

        $this->assertSame(['Holder'], $this->service()->audienceQuery(['has_points' => true])->pluck('name')->all());
    }

    /* --------------------------------------------------------- the freeze */

    public function test_queueing_writes_a_row_per_person_before_anything_is_sent(): void
    {
        $this->customer('One', '9000000001');
        $this->customer('Two', '9000000002');

        $campaign = $this->service()->schedule($this->campaign());

        $this->assertSame(Campaign::SCHEDULED, $campaign->status);
        $this->assertSame(2, $campaign->audience_count);
        $this->assertSame(2, CampaignRecipient::query()->count());

        // Frozen, not sent.
        $this->assertSame([], $this->sms->sent);
    }

    public function test_the_audience_does_not_shift_once_it_is_frozen(): void
    {
        $this->customer('One', '9000000001');

        $campaign = $this->service()->schedule($this->campaign());

        // Somebody new arrives after the campaign was queued.
        $this->customer('Late arrival', '9000000002');

        $this->service()->run($campaign);

        // They are not in it. A segment that shifted mid-flight would make a
        // campaign impossible to describe afterwards.
        $this->assertSame(1, $campaign->fresh()->sent_count);
        $this->assertCount(1, $this->sms->sent);
    }

    public function test_re_queueing_replaces_the_old_recipient_list(): void
    {
        $this->customer('One', '9000000001');

        $campaign = $this->service()->schedule($this->campaign());
        $this->assertSame(1, $campaign->audience_count);

        // Somebody sends it back to draft, a customer arrives, and it is
        // queued again. The rows from last time must not survive into the
        // new audience, and must not blow up the insert either.
        $campaign->forceFill(['status' => Campaign::DRAFT])->save();
        $this->customer('Two', '9000000002');

        $requeued = $this->service()->schedule($campaign->fresh());

        $this->assertSame(2, $requeued->audience_count);
        $this->assertSame(2, CampaignRecipient::query()->count());
    }

    public function test_a_segment_matching_nobody_is_refused_before_it_is_queued(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matches nobody');

        $this->service()->schedule($this->campaign(['min_spend' => 999999]));
    }

    /* ---------------------------------------------------------- sending */

    public function test_the_name_is_merged_into_each_message(): void
    {
        $this->customer('Mrs Mehta', '9000000001');

        $campaign = $this->service()->schedule($this->campaign());
        $this->service()->run($campaign);

        $this->assertStringContainsString('Mrs Mehta', $this->sms->sent[0]['message']);
    }

    public function test_a_batch_at_a_time_and_the_rest_waits(): void
    {
        foreach (range(1, 5) as $i) {
            $this->customer('Guest '.$i, '90000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $campaign = $this->service()->schedule($this->campaign());

        $this->service()->run($campaign, limit: 2);

        $fresh = $campaign->fresh();

        // Still sending: four hundred requests in one run is a run that gets
        // killed at about the fortieth.
        $this->assertSame(2, $fresh->sent_count);
        $this->assertSame(Campaign::SENDING, $fresh->status);

        $this->service()->run($fresh, limit: 10);

        $this->assertSame(5, $campaign->fresh()->sent_count);
        $this->assertSame(Campaign::SENT, $campaign->fresh()->status);
    }

    public function test_a_run_interrupted_half_way_resumes_without_sending_twice(): void
    {
        foreach (range(1, 4) as $i) {
            $this->customer('Guest '.$i, '90000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $campaign = $this->service()->schedule($this->campaign());

        $this->service()->run($campaign, limit: 2);
        // A deploy, a timeout, a machine going down. The next tick picks it up.
        $this->service()->run($campaign->fresh(), limit: 10);

        $this->assertCount(4, $this->sms->sent);
        $this->assertSame(4, $campaign->fresh()->sent_count);
    }

    public function test_a_provider_that_refuses_is_recorded_per_person(): void
    {
        $this->customer('One', '9000000001');

        app(SmsManager::class)->swap(new DeadSender());

        $campaign = $this->service()->schedule($this->campaign());
        $this->service()->run($campaign);

        // The campaign is not burnt; the row says who did not get it.
        $this->assertSame(1, $campaign->fresh()->failed_count);
        $this->assertSame(
            CampaignRecipient::FAILED,
            CampaignRecipient::query()->firstOrFail()->status,
        );
    }

    public function test_a_channel_with_no_provider_cannot_be_queued(): void
    {
        $this->customer('One', '9000000001');

        app(SmsManager::class)->swap(new UnconfiguredSender());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No SMS provider is set up');

        $this->service()->schedule($this->campaign());
    }

    /* -------------------------------------------------------- stopping */

    public function test_stopping_leaves_what_was_sent_and_skips_the_rest(): void
    {
        foreach (range(1, 4) as $i) {
            $this->customer('Guest '.$i, '90000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $campaign = $this->service()->schedule($this->campaign());
        $this->service()->run($campaign, limit: 2);

        $this->service()->cancel($campaign->fresh());

        $this->assertSame(Campaign::CANCELLED, $campaign->fresh()->status);
        // Marked skipped rather than deleted, so the record still shows who
        // was in the audience.
        $this->assertSame(2, CampaignRecipient::query()->where('status', CampaignRecipient::SKIPPED)->count());
        $this->assertCount(2, $this->sms->sent);
    }

    public function test_a_finished_campaign_cannot_be_unsent(): void
    {
        $this->customer('One', '9000000001');

        $campaign = $this->service()->schedule($this->campaign());
        $this->service()->run($campaign);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be unsent');

        $this->service()->cancel($campaign->fresh());
    }

    /* -------------------------------------------------------- the screens */

    public function test_the_preview_says_how_many_people_it_reaches(): void
    {
        $this->customer('One', '9000000001');
        $this->customer('Two', '9000000002');

        $response = $this->postJson(route('admin.campaigns.preview'), []);

        $response->assertOk();
        $this->assertSame(2, $response->json('data.count'));
        $this->assertStringContainsString('2 people', $response->json('data.label'));
    }

    public function test_saving_a_draft_sends_nothing(): void
    {
        $this->customer('One', '9000000001');

        $this->postJson(route('admin.campaigns.store'), [
            'name' => 'September regulars',
            'channel' => 'sms',
            'body' => 'Hello {name}',
            'not_visited_for_days' => 60,
        ])->assertOk();

        $campaign = Campaign::query()->firstOrFail();

        $this->assertSame(Campaign::DRAFT, $campaign->status);
        $this->assertSame(['not_visited_for_days' => 60], $campaign->segment);
        $this->assertSame([], $this->sms->sent);
    }

    public function test_queueing_over_http_reports_the_audience(): void
    {
        $this->customer('One', '9000000001');
        $campaign = $this->campaign();

        $response = $this->postJson(route('admin.campaigns.send', $campaign));

        $response->assertOk();
        $this->assertStringContainsString('1 people', $response->json('message'));
    }

    public function test_sending_needs_its_own_right(): void
    {
        $this->customer('One', '9000000001');
        $campaign = $this->campaign();

        $reader = User::factory()->create(['tenant_id' => $this->shop()->tenant_id, 'is_admin' => true]);
        $reader->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $reader->forceFill(['current_shop_id' => $this->shop()->id, 'all_shops_view' => false])->save();
        $reader->givePermissionTo(['dashboard.overview.view', 'crm.campaigns.view', 'crm.campaigns.create']);

        $this->actingAs($reader->fresh());
        CurrentShop::forget();

        // Writing a draft costs nothing; sending to four hundred people is
        // not recallable. They are deliberately different rights.
        $this->postJson(route('admin.campaigns.send', $campaign))->assertForbidden();
    }

    public function test_the_command_sends_what_is_due(): void
    {
        $this->customer('One', '9000000001');

        $this->service()->schedule($this->campaign());

        $this->artisan('campaigns:send')
            ->expectsOutputToContain('1 message(s) attempted.')
            ->assertSuccessful();
    }

    public function test_a_campaign_scheduled_for_later_is_not_sent_yet(): void
    {
        $this->customer('One', '9000000001');

        $this->service()->schedule($this->campaign(), now()->addHours(3));

        $this->artisan('campaigns:send')
            ->expectsOutputToContain('Nothing due.')
            ->assertSuccessful();

        $this->assertSame([], $this->sms->sent);
    }
}

/** Records instead of sending. */
class FakeSender implements SmsGateway
{
    /** @var array<int, array{to: string, message: string}> */
    public array $sent = [];

    public function key(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $message): bool
    {
        $this->sent[] = ['to' => $to, 'message' => $message];

        return true;
    }
}

/** Configured, but refusing - the commonest real failure. */
class DeadSender implements SmsGateway
{
    public function key(): string
    {
        return 'dead';
    }

    public function label(): string
    {
        return 'Dead';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $message): bool
    {
        return false;
    }
}

/** Nobody set it up at all. */
class UnconfiguredSender implements SmsGateway
{
    public function key(): string
    {
        return 'none';
    }

    public function label(): string
    {
        return 'None';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $to, string $message): bool
    {
        return false;
    }
}
