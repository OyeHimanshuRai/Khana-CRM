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
 * Without it anybody could open an account in somebody else's name - and,
 * because an address can only be used once, keep the real owner from ever
 * signing up. Until the link in the welcome email is opened the account
 * shows nothing but "confirm your email". Staff an owner or the platform
 * creates are not asked: whoever made the account vouched for the address.
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

    public function test_a_new_sign_up_has_to_confirm_its_email(): void
    {
        $owner = $this->signUp();

        $this->assertTrue($owner->must_verify_email);
        $this->assertNull($owner->email_verified_at);
        $this->assertTrue($owner->needsEmailVerification());

        Mail::assertQueued(TrialWelcomeMail::class, fn (TrialWelcomeMail $mail) => $mail->hasTo('asha@spicegarden.test')
            && str_contains($mail->render(), '/email/verify/'.$owner->id.'/'));
    }

    public function test_until_then_the_account_opens_nothing_but_the_confirmation_screen(): void
    {
        $owner = $this->signUp();

        $this->get('/superadmin/dashboard')->assertRedirect(route('admin.verification.notice'));
        $this->resetState();
        $this->getJson('/superadmin/products')->assertForbidden()
            ->assertJsonPath('message', 'Please confirm your email address first. We sent a link to asha@spicegarden.test.');
        $this->resetState();
        $this->get(route('admin.verification.notice'))->assertOk()->assertSee('asha@spicegarden.test');
        $this->resetState();
        $this->post('/superadmin/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_link_confirms_the_address_and_opens_the_account(): void
    {
        $owner = $this->signUp();

        $this->get($this->verifyUrl($owner))->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull($owner->fresh()->email_verified_at);

        $this->resetState();
        $this->get('/superadmin/dashboard')->assertOk();
    }

    public function test_the_link_works_on_another_device_where_nobody_is_signed_in(): void
    {
        $owner = $this->signUp();
        Auth::guard('web')->logout();
        $this->flushSession();
        $this->resetState();

        $this->get($this->verifyUrl($owner))->assertRedirect(route('login'))->assertSessionHas('status');
        $this->assertNotNull($owner->fresh()->email_verified_at);
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

    public function test_the_link_can_be_sent_again_but_not_endlessly(): void
    {
        $this->signUp();

        $this->postJson(route('admin.verification.send'))->assertOk();
        Mail::assertQueued(VerifyEmailMail::class, 1);

        for ($i = 0; $i < 5; $i++) {
            $this->resetState();
            $last = $this->postJson(route('admin.verification.send'));
        }
        $last->assertStatus(429);
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
        $this->actingAs($staff->fresh())->get('/superadmin/dashboard')->assertOk();
    }
}
