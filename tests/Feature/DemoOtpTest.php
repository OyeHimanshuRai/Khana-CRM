<?php

namespace Tests\Feature;

use App\Mail\SignupOtpMail;
use App\Models\DemoRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * "Book a demo": an email that is given is confirmed with a one-time code first;
 * a phone number alone needs none.
 */
class DemoOtpTest extends TestCase
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
    }

    private function lead(array $overrides = []): array
    {
        return array_merge(['name' => 'Aditi Rao', 'email' => 'aditi@example.test', 'business_name' => 'Spice Route'], $overrides);
    }

    private function askForCode(string $email = 'aditi@example.test'): string
    {
        $this->postJson(route('demo.otp.send'), ['email' => $email])->assertOk()->assertJsonPath('success', true);

        $code = null;
        Mail::assertSent(SignupOtpMail::class, function (SignupOtpMail $mail) use (&$code, $email) {
            if ($mail->hasTo($email)) {
                $code = $mail->code;
            }

            return true;
        });

        return (string) $code;
    }

    public function test_an_unconfirmed_email_is_refused_and_nothing_is_saved(): void
    {
        $this->postJson(route('demo-request.store'), $this->lead())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'Please verify your email with the 6-digit code first.']);

        $this->assertDatabaseCount('demo_requests', 0);
    }

    public function test_the_right_code_lets_the_request_through(): void
    {
        $code = $this->askForCode();

        $this->postJson(route('demo.otp.verify'), ['email' => 'aditi@example.test', 'otp' => $code])->assertOk();
        $this->postJson(route('demo-request.store'), $this->lead())->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('demo_requests', ['email' => 'aditi@example.test', 'name' => 'Aditi Rao']);
        $this->assertNull(session(\App\Http\Controllers\DemoOtpController::SESSION_KEY), 'the confirmed address is used up');
    }

    public function test_a_wrong_code_confirms_nothing(): void
    {
        $this->askForCode();

        $this->postJson(route('demo.otp.verify'), ['email' => 'aditi@example.test', 'otp' => '000000'])->assertStatus(422);
        $this->postJson(route('demo-request.store'), $this->lead())->assertStatus(422);
        $this->assertDatabaseCount('demo_requests', 0);
    }

    public function test_a_confirmed_address_does_not_cover_another(): void
    {
        $code = $this->askForCode();
        $this->postJson(route('demo.otp.verify'), ['email' => 'aditi@example.test', 'otp' => $code])->assertOk();

        $this->postJson(route('demo-request.store'), $this->lead(['email' => 'someone@else.test']))->assertStatus(422);
        $this->assertDatabaseCount('demo_requests', 0);
    }

    public function test_a_phone_number_alone_needs_no_code(): void
    {
        $this->postJson(route('demo-request.store'), ['name' => 'Rahul', 'phone' => '9876543210'])->assertOk();

        $this->assertDatabaseHas('demo_requests', ['name' => 'Rahul']);
        $this->assertSame(1, DemoRequest::query()->count());
    }

    public function test_the_code_is_not_the_one_a_sign_up_uses(): void
    {
        // A code asked for on the sign-up form does not confirm an address on the demo form.
        $this->postJson(route('register.otp.send'), ['email' => 'aditi@example.test'])->assertOk();
        $signupCode = null;
        Mail::assertSent(SignupOtpMail::class, function (SignupOtpMail $mail) use (&$signupCode) {
            $signupCode = $mail->code;

            return true;
        });

        $this->postJson(route('demo.otp.verify'), ['email' => 'aditi@example.test', 'otp' => (string) $signupCode])->assertStatus(422);
    }

    public function test_the_forms_carry_the_code_step_and_the_faq_page_has_the_window(): void
    {
        $this->get('/demo')->assertOk()->assertSee('data-send-url="'.route('demo.otp.send').'"', false)->assertSee('Send OTP to this email');
        $this->get('/')->assertOk()->assertSee('data-verify-url="'.route('demo.otp.verify').'"', false);
        $this->get('/faq')->assertOk()->assertSee('data-demo-modal', false)->assertSee('demo-modal.js', false)->assertSee('Ask us - book a demo');
    }

    public function test_a_code_cannot_be_asked_for_every_second(): void
    {
        $this->askForCode();

        $this->postJson(route('demo.otp.send'), ['email' => 'aditi@example.test'])->assertStatus(429)->assertJsonPath('success', false);
    }
}
