<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A subscription never becomes free-for-ever by accident.
 *
 * "No end date" is a real thing a platform gives - an in-house account, a
 * partner - but it has to be asked for. Putting a business on a plan with no
 * trial and no time left used to leave `ends_at` empty, which reads as
 * perpetual: one plan change on a lapsed account handed it the software for
 * nothing, for good. And a company created from the platform screen had no
 * subscription at all, which reads as "not being sold" - every module, no
 * limits, no end.
 */
class SubscriptionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->orderBy('id')->firstOrFail();
    }

    private function plan(string $code = 'starter'): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function latest(int $tenantId): Subscription
    {
        PlanAccess::forget();

        return Subscription::query()->where('tenant_id', $tenantId)->latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------- the service */

    public function test_no_trial_and_no_time_left_means_unpaid_not_perpetual(): void
    {
        $sub = $this->service()->subscribe($this->tenant(), $this->plan(), Plan::MONTHLY, 0);

        $this->assertNotNull($sub->ends_at);
        $this->assertFalse($sub->onTrial());
        $this->assertSame(Subscription::PAST_DUE, $sub->state(), 'unpaid: the grace window, then nothing');

        Carbon::setTestNow(now()->addDays($sub->grace_days + 1));
        $this->assertFalse($sub->isUsable(), 'nothing was paid and no trial was given');
        Carbon::setTestNow();

        // A payment is what starts the term, from today.
        app(SubscriptionService::class)->recordPayment($sub, 799);
        $this->assertTrue($sub->fresh()->isUsable());
        $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $sub->fresh()->ends_at->timestamp, 60);
    }

    public function test_changing_the_plan_of_a_lapsed_business_does_not_hand_it_the_software_for_free(): void
    {
        $first = $this->service()->subscribe($this->tenant(), $this->plan());
        $first->forceFill(['trial_ends_at' => null, 'ends_at' => now()->subDays(10)])->save();

        // The platform moves them to another plan and leaves "trial" empty, as the form allows.
        $this->actingAs($this->admin())
            ->postJson(route('admin.subscriptions.store', $this->tenant()), [
                'plan_id' => $this->plan('restaurant')->id,
                'billing_period' => 'monthly',
            ])->assertOk();

        $sub = $this->latest($this->tenant()->id);
        $this->assertNotNull($sub->ends_at);

        Carbon::setTestNow(now()->addYears(3));
        $this->assertFalse($sub->fresh()->isUsable());
    }

    public function test_a_plan_change_with_time_left_keeps_that_time(): void
    {
        $first = $this->service()->subscribe($this->tenant(), $this->plan());
        $first->forceFill(['trial_ends_at' => null, 'ends_at' => now()->addDays(12)])->save();

        $sub = $this->service()->subscribe($this->tenant(), $this->plan('restaurant'), Plan::MONTHLY, 0);

        $this->assertEqualsWithDelta(now()->addDays(12)->timestamp, $sub->ends_at->timestamp, 5);
    }

    public function test_no_end_date_is_given_only_when_asked_for(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.subscriptions.store', $this->tenant()), [
                'plan_id' => $this->plan('chain')->id,
                'billing_period' => 'monthly',
                'trial_days' => 0,
                'perpetual' => 1,
                'note' => 'In-house account',
            ])->assertOk()
            ->assertJsonPath('success', true);

        $sub = $this->latest($this->tenant()->id);
        $this->assertNull($sub->ends_at);
        $this->assertTrue($sub->isUsable());
    }

    /* ---------------------------------------------- creating a company */

    public function test_a_company_created_by_the_platform_starts_on_a_plan(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/superadmin/tenants', [
                'name' => 'Meenakshi Foods', 'code' => 'MNF',
                'plan_id' => $this->plan('restaurant')->id, 'billing_period' => 'monthly',
            ])->assertOk();

        $tenant = Tenant::query()->where('code', 'MNF')->firstOrFail();
        $sub = $this->latest($tenant->id);

        $this->assertSame($this->plan('restaurant')->id, $sub->plan_id);
        $this->assertNull($sub->shop_id);
        $this->assertTrue($sub->onTrial(), "the plan's own trial");
        $this->assertSame($this->plan('restaurant')->trial_days, $sub->daysLeft());
    }

    public function test_a_company_cannot_be_created_without_a_plan_while_plans_are_on_sale(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/superadmin/tenants', ['name' => 'Planless Co', 'code' => 'PLC'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_id');

        $this->assertFalse(Tenant::query()->where('code', 'PLC')->exists());
    }

    public function test_a_company_can_be_created_without_a_plan_where_nothing_is_sold(): void
    {
        Plan::query()->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson('/superadmin/tenants', ['name' => 'In House', 'code' => 'INH'])
            ->assertOk();

        $this->assertTrue(Tenant::query()->where('code', 'INH')->exists());
    }

    public function test_the_create_form_offers_the_plans(): void
    {
        CurrentTenant::forget();
        CurrentShop::forget();

        $this->actingAs($this->admin())
            ->get('/superadmin/tenants/create')
            ->assertOk()
            ->assertSee('name="plan_id"', false)
            ->assertSee('Restaurant');
    }
}
