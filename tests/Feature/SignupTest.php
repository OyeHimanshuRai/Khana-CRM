<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A restaurant opening its own account (§21).
 *
 * What is worth holding here is not "the form posts". It is that the four rows
 * an account is made of all land, joined to each other, and that the account
 * they add up to is one somebody can actually work in:
 *
 *   - the owner can open the back office, because they hold Tenant Owner and
 *     are attached to the outlet that was just created for them;
 *   - the outlet is on the plan that was clicked, for the term that plan sells;
 *   - a plan with no trial does NOT open a working account for nothing, which
 *     is the failure that would cost real money;
 *   - and none of it happens twice, or half-way.
 */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries a sub-path, which would prefix every test request
        // and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        $this->seed();
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return [
            'business_name' => 'Sharma Dhaba',
            'outlet_name' => '',
            'city' => 'Jaipur',
            'name' => 'Ravi Sharma',
            'email' => 'ravi@sharmadhaba.test',
            'mobile' => '9876543210',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'plan' => 'starter',
            'period' => Plan::MONTHLY,
            'terms' => '1',
            ...$overrides,
        ];
    }

    /* ------------------------------------------------------------- the form */

    public function test_step_one_is_public_and_asks_only_for_a_plan(): void
    {
        $this->assertGuest();

        $response = $this->get('/signup')->assertOk();

        $response->assertSee('Which plan fits your restaurant?');
        $response->assertSee('Starter');

        /*
         | Nothing else is asked for yet. The business name, the city and the
         | password used to sit under the price list - a long form on a phone
         | with the one decision already made scrolling out of sight.
         */
        $response->assertDontSee('name="business_name"', false);
        $response->assertDontSee('name="password"', false);
    }

    /** And step one leads to step two for the plan it is showing. */
    public function test_step_one_continues_to_the_details(): void
    {
        $this->get('/signup/restaurant')
            ->assertOk()
            ->assertSee(route('signup.details', ['plan' => 'restaurant']), false);
    }

    public function test_step_two_carries_the_plan_and_asks_for_the_rest(): void
    {
        $html = $this->get('/signup/restaurant/details')->assertOk()->getContent();

        $this->assertStringContainsString('name="plan" value="restaurant"', $html);
        $this->assertStringContainsString('name="business_name"', $html);
        $this->assertStringContainsString('name="password"', $html);

        // And a way back, because a plan can be changed until it is bought.
        $this->assertStringContainsString(route('signup', ['plan' => 'restaurant']), $html);
    }

    /** A plan nobody sells has no details worth collecting. */
    public function test_step_two_for_an_unknown_plan_goes_back_to_step_one(): void
    {
        $this->get('/signup/there-is-no-such-plan/details')
            ->assertRedirect(route('signup'));
    }

    /** The plan clicked on a pricing card is the one the page opens on. */
    public function test_it_opens_on_the_plan_named_in_the_path(): void
    {
        $html = $this->get('/signup/restaurant')->assertOk()->getContent();

        // The tab that is open says so, for a reader as well as for the eye.
        $this->assertMatchesRegularExpression('/signup\/restaurant"[^>]*aria-current="true"/s', $html);
        $this->assertDoesNotMatchRegularExpression('/signup\/starter"[^>]*aria-current="true"/s', $html);
    }

    /**
     * The old query-string form still works, once.
     *
     * Links to `?plan=` are already out there. Answering both shapes for ever
     * would be one page under two addresses - a split ranking, and two
     * versions of every link anybody shares - so it is redirected rather than
     * served.
     */
    public function test_the_old_query_string_link_is_redirected(): void
    {
        $this->get('/signup?plan=restaurant')
            ->assertRedirect(route('signup', ['plan' => 'restaurant']));

        $this->get('/signup?plan=restaurant&period=yearly')
            ->assertRedirect(route('signup', ['plan' => 'restaurant', 'period' => 'yearly']));
    }

    /** A plan slug nobody recognises is a plan picker, not a dead end. */
    public function test_an_unknown_plan_in_the_path_falls_back(): void
    {
        $this->get('/signup/there-is-no-such-plan')
            ->assertOk()
            ->assertSee('Which plan fits your restaurant?');
    }

    /** Somebody already signed in has an account; they are sent to theirs. */
    public function test_a_signed_in_user_is_sent_to_their_dashboard(): void
    {
        $this->actingAs(User::where('is_admin', true)->firstOrFail());

        $this->get('/signup')->assertRedirect(route('admin.dashboard'));
    }

    /* ---------------------------------------------------------- the account */

    public function test_it_creates_the_whole_account_and_signs_them_in(): void
    {
        $response = $this->post('/signup', $this->form());

        $response->assertRedirect(route('admin.dashboard'));

        $tenant = Tenant::where('name', 'Sharma Dhaba')->firstOrFail();
        $shop = Shop::where('tenant_id', $tenant->id)->firstOrFail();
        $owner = User::where('email', 'ravi@sharmadhaba.test')->firstOrFail();

        // The outlet takes the business's name when they left the field alone.
        $this->assertSame('Sharma Dhaba', $shop->name);
        $this->assertSame('Jaipur', $shop->city);

        // Joined up, all four ways round.
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertSame($shop->id, $owner->current_shop_id);
        $this->assertTrue($owner->shops()->where('shops.id', $shop->id)->exists());

        // And able to work: the back office needs both of these.
        $this->assertTrue($owner->is_admin);
        $this->assertTrue($owner->hasRole(User::TENANT_OWNER));

        $this->assertAuthenticatedAs($owner);
    }

    /**
     * Codes are unique, which a second restaurant of the same name tests.
     *
     * Both tables carry a unique index on `code`; a collision here would be a
     * five-hundred on the second signup rather than a field error.
     */
    public function test_a_second_business_with_the_same_name_still_signs_up(): void
    {
        $this->post('/signup', $this->form())->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->post('/signup', $this->form([
            'email' => 'another@sharmadhaba.test',
        ]))->assertRedirect();

        $this->assertSame(2, Tenant::where('name', 'Sharma Dhaba')->count());
        $this->assertSame(
            2,
            Tenant::where('name', 'Sharma Dhaba')->distinct()->count('code'),
        );
    }

    /* ------------------------------------------------------ the subscription */

    public function test_it_puts_the_outlet_on_the_plan_with_its_trial(): void
    {
        $this->post('/signup', $this->form())->assertRedirect(route('admin.dashboard'));

        $shop = Shop::where('name', 'Sharma Dhaba')->firstOrFail();
        $plan = Plan::where('slug', 'starter')->firstOrFail();

        $subscription = Subscription::where('shop_id', $shop->id)->firstOrFail();

        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertSame(Plan::MONTHLY, $subscription->billing_period);
        $this->assertTrue($subscription->onTrial());
        $this->assertSame(
            $plan->trial_days,
            (int) now()->startOfDay()->diffInDays($subscription->trial_ends_at->startOfDay(), false),
        );

        // A trial is a working account: the dashboard opens.
        $this->get(route('admin.dashboard'))->assertOk();
    }

    /**
     * A plan with no trial must not hand out a working account for nothing.
     *
     * SubscriptionService leaves `ends_at` null when there is no trial and no
     * earlier term, and null means perpetual - so without SignupService
     * closing the term, signing up for the most expensive plan would be the
     * cheapest way to use this software forever.
     */
    public function test_a_plan_without_a_trial_is_locked_until_it_is_paid_for(): void
    {
        $plan = Plan::where('slug', 'starter')->firstOrFail();
        $plan->forceFill(['trial_days' => 0])->save();

        $this->post('/signup', $this->form())
            ->assertRedirect(route('admin.billing.show'));

        $subscription = Subscription::latest('id')->firstOrFail();

        $this->assertFalse($subscription->isUsable());
        $this->assertSame(Subscription::EXPIRED, $subscription->state());

        // Locked out of the app, and let into the one page that ends the lock.
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.billing.show'));
        $this->get(route('admin.billing.show'))->assertOk();
    }

    /* ------------------------------------------------------------ refusals */

    public function test_it_refuses_an_email_that_already_has_an_account(): void
    {
        $taken = User::where('is_admin', true)->firstOrFail();

        $this->post('/signup', $this->form(['email' => $taken->email]))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseMissing('tenants', ['name' => 'Sharma Dhaba']);
    }

    public function test_it_refuses_a_mismatched_password(): void
    {
        $this->post('/signup', $this->form(['password_confirmation' => 'something-else']))
            ->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertDatabaseMissing('tenants', ['name' => 'Sharma Dhaba']);
    }

    public function test_it_refuses_without_the_terms(): void
    {
        $this->post('/signup', $this->form(['terms' => null]))
            ->assertSessionHasErrors('terms');

        $this->assertDatabaseMissing('tenants', ['name' => 'Sharma Dhaba']);
    }

    /**
     * The honeypot: accepted, and nothing written.
     *
     * Told nothing about why, because an error message is how whoever wrote
     * the bot learns to leave the field blank next time.
     */
    public function test_the_honeypot_writes_nothing(): void
    {
        $this->post('/signup', $this->form(['website' => 'http://spam.example']))
            ->assertRedirect(route('signup'));

        $this->assertGuest();
        $this->assertDatabaseMissing('tenants', ['name' => 'Sharma Dhaba']);
    }
}
