<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * An admin lengthening a trial: bounded, saved, enforced by the server.
 */
class TrialExtensionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        $this->resetState();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->resetState();

        parent::tearDown();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->orderBy('id')->firstOrFail();
    }

    private function trial(int $days = 7): Subscription
    {
        $plan = Plan::query()->where('code', 'starter')->firstOrFail();

        return app(SubscriptionService::class)->subscribe($this->tenant(), $plan, Plan::MONTHLY, $days);
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    private function url(): string
    {
        return route('admin.subscriptions.extend-trial', $this->tenant());
    }

    public function test_extending_adds_days_on_top_of_the_running_trial(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $sub = $this->trial(7);

        $this->actingAs($this->admin())
            ->putJson($this->url(), ['days' => 5])
            ->assertOk()
            ->assertJsonPath('success', true);

        $sub->refresh();
        $this->assertTrue($sub->trial_ends_at->equalTo(Carbon::parse('2026-06-13 10:00:00')));
        $this->assertTrue($sub->ends_at->equalTo($sub->trial_ends_at));
    }

    public function test_extending_a_lapsed_trial_counts_from_now_and_reopens_the_account(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $sub = $this->trial(7);

        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->assertFalse($sub->fresh()->isUsable());

        app(SubscriptionService::class)->extendTrial($sub, 3);

        $sub->refresh();
        $this->assertTrue($sub->ends_at->equalTo(Carbon::parse('2026-07-04 10:00:00')));
        $this->assertTrue($sub->isUsable());
    }

    public function test_the_length_is_validated_and_capped_on_the_server(): void
    {
        $this->trial();
        $this->actingAs($this->admin());

        foreach ([0, -1, 91, 'abc', null] as $bad) {
            $this->putJson($this->url(), ['days' => $bad])->assertStatus(422)->assertJsonValidationErrors('days');
        }
    }

    public function test_the_service_refuses_out_of_range_values_too(): void
    {
        $sub = $this->trial();

        $this->expectException(RuntimeException::class);
        app(SubscriptionService::class)->extendTrial($sub, 1000);
    }

    public function test_a_cancelled_subscription_cannot_be_extended(): void
    {
        $sub = $this->trial();
        app(SubscriptionService::class)->cancel($sub, null, true);

        $this->actingAs($this->admin())
            ->putJson($this->url(), ['days' => 5])
            ->assertStatus(422);
    }

    public function test_a_tenant_owner_cannot_extend_their_own_trial(): void
    {
        $this->trial();

        $owner = User::factory()->create(['tenant_id' => $this->tenant()->id, 'is_admin' => true]);
        $owner->assignRole('Tenant Owner');

        $before = $this->tenant()->subscription()->first()->ends_at;

        $this->actingAs($owner)->putJson($this->url(), ['days' => 30])->assertForbidden();

        $this->assertTrue($before->equalTo($this->tenant()->subscription()->first()->ends_at));
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->trial();

        $this->putJson($this->url(), ['days' => 5])->assertUnauthorized();
    }
}
