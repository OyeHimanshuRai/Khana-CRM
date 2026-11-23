<?php

namespace Tests\Feature;

use App\Mail\SignupOtpMail;
use App\Models\User;
use App\Support\SignupOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The email is confirmed with a one-time code BEFORE the sign-up form can be
 * submitted: the code is mailed, checked, remembered in the visitor's session,
 * and the sign-up refuses an address that was not confirmed.
 */
class SignupOtpTest extends TestCase
{
    use RefreshDatabase;

    protected bool $autoConfirmSignupEmail = false;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        Mail::fake();
        Cache::flush();
        RateLimiter::clear('signup-otp-send:'.sha1('asha@spicegarden.test'));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Spice Garden', 'name' => 'Asha Rao', 'email' => 'asha@spicegarden.test',
            'mobile' => '+91 98765 43210', 'password' => 'Secret123',
            'plan' => 'restaurant', 'period' => 'monthly', 'terms' => '1',
        ], $overrides);
    }

    /** Ask for a code and read it from the mail that was sent. */
    private function askForCode(string $email = 'asha@spicegarden.test'): string
    {
        $this->postJson(route('register.otp.send'), ['email' => $email])->assertOk()->assertJsonPath('success', true);

        $code = null;
        Mail::assertSent(SignupOtpMail::class, function (SignupOtpMail $mail) use (&$code, $email) {
            if ($mail->hasTo($email)) {
                $code = $mail->code;
            }

            return true;
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $code);

        return (string) $code;
    }

    public function test_the_form_has_the_code_step_and_hides_register_until_it_is_done(): void
    {
        $this->get('/register')->assertOk()
            ->assertSee('Send OTP to this email')
            ->assertSee('data-register-submit hidden', false)
            ->assertSee('Verify your email with the OTP above to continue.');
    }

    public function test_a_code_is_mailed_to_the_address_and_is_six_digits(): void
    {
        $code = $this->askForCode();

        Mail::assertSent(SignupOtpMail::class, fn (SignupOtpMail $mail) => $mail->hasTo('asha@spicegarden.test')
            && str_contains($mail->render(), $code)
            && str_contains($mail->envelope()->subject, $code));
    }

    public function test_signing_up_is_refused_until_the_address_is_confirmed(): void
    {
        $this->postJson('/register', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'Please verify your email with the 6-digit code first.']);

        $this->assertDatabaseMissing('users', ['email' => 'asha@spicegarden.test']);
    }

    public function test_the_right_code_confirms_the_address_and_signing_up_works(): void
    {
        $code = $this->askForCode();

        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $code])
            ->assertOk()->assertJsonPath('success', true);

        $this->postJson('/register', $this->payload())->assertOk()->assertJsonPath('success', true);

        $owner = User::query()->withoutGlobalScopes()->where('email', 'asha@spicegarden.test')->firstOrFail();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse($owner->needsEmailVerification());
        $this->assertGuest();
    }

    public function test_a_wrong_code_does_not_confirm_anything(): void
    {
        $this->askForCode();

        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => '000000'])
            ->assertStatus(422)->assertJsonPath('message', 'That code is not right. Check the email and try again.');

        $this->postJson('/register', $this->payload())->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'asha@spicegarden.test']);
    }

    public function test_five_wrong_codes_lock_the_code_even_for_the_right_one(): void
    {
        $code = $this->askForCode();
        $wrong = $code === '123456' ? '654321' : '123456';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $wrong])->assertStatus(422);
        }

        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $code])
            ->assertStatus(422)->assertJsonPath('message', 'Too many wrong codes. Ask for a new code.');
    }

    public function test_a_code_for_one_address_does_not_confirm_another(): void
    {
        $code = $this->askForCode();

        $this->postJson(route('register.otp.verify'), ['email' => 'someone@else.test', 'otp' => $code])->assertStatus(422);

        // And an address confirmed in the session is not a licence to sign up as another.
        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $code])->assertOk();
        $this->postJson('/register', $this->payload(['email' => 'someone@else.test']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'someone@else.test']);
    }

    public function test_a_code_works_once(): void
    {
        $code = $this->askForCode();

        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $code])->assertOk();
        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $code])->assertStatus(422);
    }

    public function test_the_code_expires(): void
    {
        $code = $this->askForCode();

        $this->travel(SignupOtp::TTL_MINUTES + 1)->minutes();

        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $code])
            ->assertStatus(422)->assertJsonPath('message', 'That code has expired. Ask for a new code.');
    }

    public function test_a_new_code_cannot_be_asked_for_every_second(): void
    {
        $this->askForCode();

        $this->postJson(route('register.otp.send'), ['email' => 'asha@spicegarden.test'])
            ->assertStatus(429)->assertJsonPath('data.wait', fn ($wait) => $wait > 0 && $wait <= SignupOtp::RESEND_SECONDS);

        $this->travel(SignupOtp::RESEND_SECONDS + 1)->seconds();
        $this->postJson(route('register.otp.send'), ['email' => 'asha@spicegarden.test'])->assertOk();
    }

    public function test_an_address_that_already_has_an_account_gets_no_code(): void
    {
        User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail();

        $this->postJson(route('register.otp.send'), ['email' => 'ADMIN@erp.test'])
            ->assertStatus(422)->assertJsonPath('message', 'An account with this email already exists. Try signing in instead.');

        Mail::assertNotSent(SignupOtpMail::class);
    }

    public function test_a_mail_failure_says_so_and_lets_them_try_again_at_once(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP connect failed'));

        $this->postJson(route('register.otp.send'), ['email' => 'asha@spicegarden.test'])
            ->assertStatus(503)->assertJsonPath('success', false);

        $this->assertSame(0, SignupOtp::waitSeconds('asha@spicegarden.test'));
    }

    public function test_the_session_is_cleared_after_signing_up(): void
    {
        $code = $this->askForCode();
        $this->postJson(route('register.otp.verify'), ['email' => 'asha@spicegarden.test', 'otp' => $code])->assertOk();
        $this->postJson('/register', $this->payload())->assertOk();

        $this->assertNull(session(SignupOtp::SESSION_KEY));
    }

    public function test_the_code_is_never_stored_in_the_clear(): void
    {
        $code = $this->askForCode();

        $this->assertStringNotContainsString($code, json_encode(Cache::get('signup-otp:'.sha1('asha@spicegarden.test'))));
    }

    public function test_the_logo_travels_inside_the_email(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('settings/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        \App\Models\Setting::put(['site_logo' => 'settings/logo.png']);

        $mailer = (new \Illuminate\Mail\MailManager($this->app))->mailer('array');
        $mailer->to('asha@spicegarden.test')->sendNow(new SignupOtpMail('123456'));

        $raw = $mailer->getSymfonyTransport()->messages()->first()->getOriginalMessage()->toString();

        $this->assertStringContainsString('Content-ID', $raw, 'the logo is attached to the email');
        $this->assertStringContainsString('cid:', $raw);
        $this->assertStringContainsString('123456', $raw);
    }
}
