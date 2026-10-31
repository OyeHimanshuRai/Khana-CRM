<?php

namespace Tests\Feature;

use App\Mail\NewSignupMail;
use App\Mail\SubscriptionNoticeMail;
use App\Mail\TrialWelcomeMail;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionNoticeService as Notice;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What a new owner is told, and when: the sign-up itself over AJAX, the two
 * emails it sends, and the countdown to the end of the trial.
 */
class SignupNoticesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        // A Wednesday morning in India; the 7-day trial ends next Wednesday.
        Carbon::setTestNow('2026-10-07 04:00:00');

        $this->seed();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Spice Garden',
            'name' => 'Asha Rao',
            'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'plan' => 'restaurant',
            'period' => 'monthly',
            'terms' => '1',
        ], $overrides);
    }

    /** Sign up the way the form does, then step out so the clock can move. */
    private function signUp(): User
    {
        $this->postJson('/register', $this->payload())->assertOk();

        Auth::guard('web')->logout();

        return User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
    }

    private function subscription(User $owner): Subscription
    {
        return Subscription::query()->where('tenant_id', $owner->tenant_id)->latest('id')->firstOrFail();
    }

    /** @return array<int, string> the kinds sent by one run */
    private function remind(): array
    {
        return app(Notice::class)->run()->pluck('kind')->all();
    }

    /* ------------------------------------------------------------ sign-up */

    public function test_the_form_signs_up_over_ajax_and_says_where_to_go(): void
    {
        $this->postJson('/register', $this->payload(['email' => '  Asha@SpiceGarden.TEST ']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('redirect', route('admin.dashboard'))
            ->assertJsonFragment(['message' => 'Welcome! Your 7-day free trial of the Restaurant plan has started. We have emailed your sign-in details.']);

        // Stored the way it will be typed to sign in.
        $this->assertDatabaseHas('users', ['email' => 'asha@spicegarden.test']);
        $this->assertAuthenticated();
    }

    public function test_a_wrong_password_comes_back_on_the_password_field(): void
    {
        $this->postJson('/register', $this->payload(['password_confirmation' => 'Different123']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password' => 'The two passwords do not match.']);

        $this->postJson('/register', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, Tenant::query()->where('name', 'Spice Garden')->count());
    }

    public function test_a_taken_email_is_refused_whatever_its_case(): void
    {
        $this->signUp();

        $this->postJson('/register', $this->payload(['email' => 'ASHA@SPICEGARDEN.TEST', 'business_name' => 'Another']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'An account with this email already exists. Try signing in instead.']);
    }

    public function test_signing_up_emails_the_owner_and_the_platform(): void
    {
        $owner = $this->signUp();

        Mail::assertQueued(TrialWelcomeMail::class, fn (TrialWelcomeMail $mail) => $mail->hasTo('asha@spicegarden.test')
            && $mail->user->is($owner));

        Mail::assertQueued(NewSignupMail::class, fn (NewSignupMail $mail) => $mail->hasTo('admin@erp.test')
            && ! $mail->hasTo('asha@spicegarden.test'));
    }

    public function test_the_welcome_email_never_carries_the_password(): void
    {
        $owner = $this->signUp();

        $html = (new TrialWelcomeMail($owner, $this->subscription($owner)))->render();

        $this->assertStringContainsString('asha@spicegarden.test', $html);
        $this->assertStringContainsString(route('login'), $html);
        $this->assertStringNotContainsString('Secret123', $html);
    }

    /* ---------------------------------------------------------- countdown */

    public function test_the_trial_countdown_says_each_thing_once_on_its_own_morning(): void
    {
        $owner = $this->signUp();
        $ends = $this->subscription($owner)->ends_at;

        $this->assertSame([], $this->remind(), 'nothing on day one');

        // Two days before the end, at the scheduled 09:00 India time.
        Carbon::setTestNow($ends->copy()->subDays(2)->setTimezone('Asia/Kolkata')->setTime(9, 0)->utc());
        $this->assertSame([Notice::ENDING_2], $this->remind());
        $this->assertSame([], $this->remind(), 'a second run sends nothing');

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->assertSame([Notice::ENDING_1], $this->remind());

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->assertSame([Notice::ENDING_0], $this->remind());

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->assertSame([Notice::ENDED], $this->remind());
        $this->assertSame([], $this->remind());

        Mail::assertQueued(SubscriptionNoticeMail::class, 4);
        Mail::assertQueued(SubscriptionNoticeMail::class, fn ($mail) => $mail->hasTo('asha@spicegarden.test'));
    }

    public function test_the_trial_really_does_lock_the_account_when_it_ends(): void
    {
        $owner = $this->signUp();
        Carbon::setTestNow($this->subscription($owner)->ends_at->copy()->addMinute());

        $this->post('/login', ['email' => 'asha@spicegarden.test', 'password' => 'Secret123']);

        $this->get(route('admin.dashboard'))->assertRedirect();
        $this->assertFalse($this->subscription($owner)->isUsable());
    }

    public function test_renewing_starts_the_countdown_again(): void
    {
        $owner = $this->signUp();
        $subscription = $this->subscription($owner);

        Carbon::setTestNow($subscription->ends_at->copy()->subDay());
        $this->assertSame([Notice::ENDING_1], $this->remind());

        // Paid for a month: a new end date, and so a new set of notices.
        $subscription->forceFill(['ends_at' => $subscription->ends_at->copy()->addMonth()])->save();

        Carbon::setTestNow($subscription->ends_at->copy()->subDay());
        $this->assertSame([Notice::ENDING_1], $this->remind());
    }

    public function test_a_business_paused_by_a_person_is_not_told_to_renew(): void
    {
        $owner = $this->signUp();
        Tenant::query()->findOrFail($owner->tenant_id)->suspend('Under review');

        Carbon::setTestNow($this->subscription($owner)->ends_at->copy()->subDay());

        $this->assertSame([], $this->remind());
    }

    public function test_an_old_row_left_by_a_plan_change_is_never_warned_about(): void
    {
        $owner = $this->signUp();
        $old = $this->subscription($owner);

        // A plan change writes a newer row; the old one's date is history.
        Subscription::query()->create([
            'tenant_id' => $old->tenant_id,
            'plan_id' => Plan::query()->where('slug', 'chain')->value('id'),
            'billing_period' => 'monthly',
            'price' => 4999,
            'currency' => 'INR',
            'starts_at' => now(),
            'ends_at' => now()->addMonths(2),
        ]);

        Carbon::setTestNow($old->ends_at->copy()->subDay());

        $this->assertSame([], $this->remind());
    }

    public function test_each_notice_renders(): void
    {
        $owner = $this->signUp();
        $subscription = $this->subscription($owner);

        foreach ([Notice::ENDING_2, Notice::ENDING_1, Notice::ENDING_0, Notice::ENDED] as $kind) {
            $mail = new SubscriptionNoticeMail($subscription, $kind, 'Asha Rao');
            $html = $mail->render();

            $this->assertStringContainsString('Spice Garden', $html, $kind);
            $this->assertStringContainsString(route('admin.subscription.mine'), $html, $kind);
            $this->assertStringContainsString('free trial', $mail->envelope()->subject, $kind);
        }

        $this->assertStringContainsString('asha@spicegarden.test', (new NewSignupMail($owner, $subscription))->render());
    }

    public function test_it_runs_every_morning_at_nine_india_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'subscriptions:remind'));

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('Asia/Kolkata', $event->timezone);

        $this->artisan('subscriptions:remind')->assertSuccessful();
    }

    public function test_the_owner_role_name_is_the_one_provisioning_assigns(): void
    {
        $owner = $this->signUp();

        $this->assertTrue($owner->hasRole(TenantProvisioningService::OWNER_ROLE));
        $this->assertCount(1, app(Notice::class)->recipientsFor($this->subscription($owner)));
    }
}
