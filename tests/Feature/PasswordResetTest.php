<?php

namespace Tests\Feature;

use App\Mail\CustomerPasswordResetMail;
use App\Mail\PasswordResetMail;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "Forgot your password?" - for the restaurant's people and its customers.
 *
 * There was no way back from a forgotten password but asking somebody with
 * the right to reset it, and a restaurant owner has nobody above them but
 * the platform. A reset link is sent to the address on the account; opening
 * it proves the address is theirs, so it also confirms a sign-up's email -
 * which is how the real owner of an address takes back an account somebody
 * else opened in their name.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        Mail::fake();
        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function staff(array $attributes = []): User
    {
        $user = User::create([
            'tenant_id' => $this->shop->tenant_id, 'name' => 'Asha', 'email' => 'asha@spicegarden.test',
            'password' => 'Old-pass-123', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $this->shop->id,
            ...$attributes,
        ]);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->givePermissionTo('dashboard.overview.view');

        return $user->fresh();
    }

    private function customer(Shop $shop, string $email = 'guest@example.test'): Customer
    {
        return Customer::create([
            'shop_id' => $shop->id, 'code' => Customer::nextCode($shop->id), 'name' => 'Guest',
            'mobile' => '9'.random_int(100000000, 999999999), 'email' => $email,
            'password' => Hash::make('old-password-1'), 'type' => 'retail', 'is_active' => true,
        ]);
    }

    /* ----------------------------------------------------------- staff */

    public function test_the_sign_in_page_offers_it(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
        $this->get(route('password.request'))->assertOk();
    }

    public function test_a_link_is_sent_to_the_address_on_the_account(): void
    {
        $user = $this->staff();

        $this->postJson(route('password.email'), ['email' => 'ASHA@spicegarden.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that address has an account, a link to reset the password is on its way.');

        Mail::assertQueued(PasswordResetMail::class, fn ($mail) => $mail->hasTo($user->email)
            && str_contains($mail->render(), '/reset-password/'));
    }

    public function test_an_unknown_or_deactivated_address_gets_the_same_answer_and_no_email(): void
    {
        $this->staff(['is_active' => false]);

        foreach (['nobody@example.test', 'asha@spicegarden.test'] as $email) {
            $this->postJson(route('password.email'), ['email' => $email])
                ->assertOk()
                ->assertJsonPath('message', 'If that address has an account, a link to reset the password is on its way.');
        }

        Mail::assertNothingQueued();
    }

    public function test_the_link_sets_a_new_password_and_signs_out_everywhere_else(): void
    {
        $user = $this->staff();
        $other = UserSession::query()->create([
            'user_id' => $user->id, 'session_id' => 'other-device', 'ip_address' => '10.0.0.9', 'login_at' => now(), 'last_activity_at' => now(),
        ]);
        $token = Password::broker('users')->createToken($user);

        $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk();

        $this->postJson(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => 'New-pass-456', 'password_confirmation' => 'New-pass-456',
        ])->assertOk();

        $this->assertTrue(Hash::check('New-pass-456', $user->fresh()->password));
        $this->assertNotNull($other->fresh()->logout_at, 'the other device is signed out');

        // And the token is spent.
        $this->postJson(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => 'Third-pass-789', 'password_confirmation' => 'Third-pass-789',
        ])->assertStatus(422);
        $this->assertTrue(Hash::check('New-pass-456', $user->fresh()->password));
    }

    public function test_a_wrong_token_changes_nothing(): void
    {
        $user = $this->staff();

        $this->postJson(route('password.update'), [
            'token' => 'not-a-real-token', 'email' => $user->email, 'password' => 'New-pass-456', 'password_confirmation' => 'New-pass-456',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertTrue(Hash::check('Old-pass-123', $user->fresh()->password));
    }

    public function test_resetting_confirms_a_sign_ups_email(): void
    {
        $user = $this->staff(['must_verify_email' => true]);
        $user->forceFill(['email_verified_at' => null, 'must_verify_email' => true])->save();
        $token = Password::broker('users')->createToken($user);

        $this->postJson(route('password.update'), [
            'token' => $token, 'email' => $user->email, 'password' => 'New-pass-456', 'password_confirmation' => 'New-pass-456',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_asking_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $last = $this->postJson(route('password.email'), ['email' => 'nobody'.$i.'@example.test']);
        }

        $last->assertStatus(429);
    }

    /* -------------------------------------------------------- customers */

    public function test_a_customer_resets_their_password_at_their_own_shop(): void
    {
        $customer = $this->customer($this->shop);

        $this->get(route('shop.login', $this->shop))->assertOk()->assertSee(route('shop.password.request', $this->shop), false);

        $this->postJson(route('shop.password.email', $this->shop), ['email' => $customer->email])->assertOk();

        $sent = null;
        Mail::assertQueued(CustomerPasswordResetMail::class, function ($mail) use ($customer, &$sent) {
            $sent = $mail;

            return $mail->hasTo($customer->email);
        });

        $this->postJson(route('shop.password.update', $this->shop), [
            'token' => $sent->token, 'email' => $customer->email, 'password' => 'new-password-2', 'password_confirmation' => 'new-password-2',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-2', $customer->fresh()->password));
    }

    public function test_the_same_email_at_another_shop_is_a_different_account(): void
    {
        $this->post('/register', [
            'business_name' => 'Rival Cafe', 'name' => 'Rival', 'email' => 'rival@example.test',
            'mobile' => '9999999999', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
            'plan' => 'chain', 'period' => 'monthly', 'terms' => '1',
        ]);
        Auth::guard('web')->logout();
        CurrentShop::forget();
        CurrentTenant::forget();
        $rivalShop = Shop::query()->withoutGlobalScopes()->where('slug', 'rival-cafe')->firstOrFail();

        $here = $this->customer($this->shop, 'same@example.test');
        $there = $this->customer($rivalShop, 'same@example.test');

        $this->postJson(route('shop.password.email', $rivalShop), ['email' => 'same@example.test'])->assertOk();

        $sent = null;
        Mail::assertQueued(CustomerPasswordResetMail::class, function ($mail) use (&$sent) {
            $sent = $mail;

            return true;
        });

        $this->postJson(route('shop.password.update', $rivalShop), [
            'token' => $sent->token, 'email' => 'same@example.test', 'password' => 'new-password-2', 'password_confirmation' => 'new-password-2',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-2', $there->fresh()->password));
        $this->assertTrue(Hash::check('old-password-1', $here->fresh()->password), 'the other shop\'s account is untouched');
    }
}
