<?php

namespace Tests\Feature;

use App\Mail\TrialWelcomeMail;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\Modules;
use App\Support\PlanAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A business that signs itself up proves the email address is theirs.
 *
 * Signing up does not sign anybody in: the visitor goes back to the home page
 * with a thank-you, and the account cannot sign in until the link in the
 * email is opened. Staff an owner or the platform creates are not asked:
 * whoever made the account vouched for the address.
 */
class SignupEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        Mail::fake();
    }

    private function resetState(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();
        PlanAccess::forget();
        Modules::forget();
        Auth::forgetGuards();
    }

    private function signUp(): User
    {
        $this->post('/register', [
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ])->assertRedirect();
        $this->resetState();

        return User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
    }

    private function verifyUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addDays(7), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);
    }

    private function signIn(string $email = 'asha@spicegarden.test', string $password = 'Secret123')
    {
        return $this->postJson('/login', ['email' => $email, 'password' => $password]);
    }

    public function test_signing_up_sends_the_link_and_signs_nobody_in(): void
    {
        $this->post('/register', [
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210', 'password' => 'Secret123', 'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ])->assertRedirect(route('landing'))->assertSessionHas('signup_notice');

        $this->assertGuest();
        $owner = User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
        $this->assertTrue($owner->needsEmailVerification());

        Mail::assertSent(TrialWelcomeMail::class, fn (TrialWelcomeMail $mail) => $mail->hasTo('asha@spicegarden.test')
            && str_contains($mail->render(), '/email/verify/'.$owner->id.'/'));
    }

    public function test_the_home_page_says_thank_you_and_where_the_link_went(): void
    {
        $this->post('/register', [
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210', 'password' => 'Secret123', 'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ]);

        $this->get(route('landing'))->assertOk()
            ->assertSee('Thank you for registering')
            ->assertSee('asha@spicegarden.test');
    }

    public function test_until_the_link_is_opened_the_account_cannot_sign_in(): void
    {
        $this->signUp();

        $this->signIn()->assertStatus(422)
            ->assertJsonPath('errors.account.0', \App\Support\AccountMessages::unverified('asha@spicegarden.test'));
        $this->assertGuest();

        $this->post('/login', ['email' => 'asha@spicegarden.test', 'password' => 'Secret123'])
            ->assertSessionHasErrors('account');
        $this->assertGuest();
    }

    public function test_a_wrong_password_still_gets_the_ordinary_message(): void
    {
        $this->signUp();

        $this->signIn(password: 'nope-nope')->assertStatus(422);
        $this->assertStringNotContainsString('verify your email', strtolower((string) $this->signIn(password: 'nope-nope')->json('errors.email.0')));
    }

    public function test_the_link_confirms_the_address_and_then_sign_in_works(): void
    {
        $owner = $this->signUp();

        $this->get($this->verifyUrl($owner))->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertNotNull($owner->fresh()->email_verified_at);
        $this->assertGuest();

        $this->resetState();
        $this->signIn()->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_session_that_is_still_open_is_ended_if_the_account_is_unconfirmed(): void
    {
        $owner = $this->signUp();

        $this->resetState();
        $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_forged_or_expired_link_confirms_nothing(): void
    {
        $owner = $this->signUp();

        $this->get(route('verification.verify', ['id' => $owner->id, 'hash' => sha1($owner->email)]))->assertForbidden();
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addDay(), ['id' => $owner->id, 'hash' => sha1('someone@else.test')]))
            ->assertForbidden();

        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $owner->id, 'hash' => sha1($owner->email)]);
        $this->get($expired)->assertForbidden();

        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_the_link_can_be_sent_again_with_the_password_but_not_endlessly(): void
    {
        $this->signUp();

        $this->postJson(route('verification.resend'), ['email' => 'asha@spicegarden.test', 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('success', true);
        Mail::assertQueued(VerifyEmailMail::class, fn ($m) => $m->hasTo('asha@spicegarden.test'));

        for ($i = 0; $i < 5; $i++) {
            $last = $this->postJson(route('verification.resend'), ['email' => 'asha@spicegarden.test', 'password' => 'Secret123']);
        }
        $last->assertStatus(429);
    }

    public function test_the_link_is_not_sent_to_a_stranger(): void
    {
        $this->signUp();

        $this->postJson(route('verification.resend'), ['email' => 'asha@spicegarden.test', 'password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Enter the email and password you registered with.');
        $this->postJson(route('verification.resend'), ['email' => 'nobody@nowhere.test', 'password' => 'Secret123'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Enter the email and password you registered with.');
        Mail::assertNotQueued(VerifyEmailMail::class);
    }

    public function test_staff_somebody_created_are_never_asked(): void
    {
        $owner = $this->signUp();
        $owner->forceFill(['email_verified_at' => now()])->save();

        $staff = User::create([
            'tenant_id' => $owner->tenant_id, 'name' => 'Cashier', 'email' => 'cashier@spicegarden.test',
            'password' => 'Cashier123', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $owner->current_shop_id,
        ]);
        $staff->shops()->attach($owner->current_shop_id, ['is_default' => true]);
        $staff->givePermissionTo('dashboard.overview.view');

        $this->assertFalse($staff->fresh()->needsEmailVerification());
        $this->resetState();
        $this->actingAs($staff->fresh())->get('/dashboard')->assertOk();
    }
}
