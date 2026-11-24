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
 * "Contact support": an email that is given is confirmed with a one-time code first;
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

    public function test_a_phone_number_alone_is_not_enough(): void
    {
        $this->postJson(route('demo-request.store'), ['name' => 'Rahul', 'phone' => '9876543210'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('demo_requests', 0);
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
        $this->get('/contact-support')->assertOk()->assertSee('data-send-url="'.route('demo.otp.send').'"', false)->assertSee('Send OTP to this email');
        $this->get('/')->assertOk()->assertSee('data-verify-url="'.route('demo.otp.verify').'"', false);
        $this->get('/faq')->assertOk()->assertSee('data-demo-modal', false)->assertSee('demo-modal.js', false)->assertSee('Ask our support team');
    }

    public function test_a_code_cannot_be_asked_for_every_second(): void
    {
        $this->askForCode();

        $this->postJson(route('demo.otp.send'), ['email' => 'aditi@example.test'])->assertStatus(429)->assertJsonPath('success', false);
    }

    /* ----------------------------------------------------------- the emails */

    public function test_a_request_emails_the_super_admins_and_the_support_address_and_a_copy_to_the_sender(): void
    {
        \App\Models\Setting::put(['company_email' => 'support@restroone.test']);
        $code = $this->askForCode();
        $this->postJson(route('demo.otp.verify'), ['email' => 'aditi@example.test', 'otp' => $code])->assertOk();

        $this->postJson(route('demo-request.store'), $this->lead(['message' => 'I need help with GST.']))->assertOk();

        // To the platform's people, once each, with the sender as the reply-to.
        Mail::assertSent(\App\Mail\SupportRequestMail::class, function ($mail) {
            return $mail->hasTo('admin@erp.test')
                && $mail->hasTo('support@restroone.test')
                && $mail->hasReplyTo('aditi@example.test')
                && str_contains($mail->render(), 'I need help with GST.')
                && str_contains($mail->render(), 'Aditi Rao')
                && str_contains($mail->render(), '/superadmin/demo-requests/');
        });

        // And a confirmation copy to the person who wrote.
        Mail::assertSent(\App\Mail\SupportRequestReceivedMail::class, function ($mail) {
            return $mail->hasTo('aditi@example.test')
                && str_contains($mail->render(), 'We received your message')
                && str_contains($mail->render(), 'I need help with GST.');
        });
    }

    public function test_a_mail_failure_never_loses_the_request(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));
        $this->withSession([\App\Http\Controllers\DemoOtpController::SESSION_KEY => 'aditi@example.test']);

        $this->postJson(route('demo-request.store'), $this->lead())->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('demo_requests', ['email' => 'aditi@example.test']);
    }

    public function test_the_page_lives_at_contact_support_and_the_old_address_redirects_there(): void
    {
        $this->assertSame('http://localhost/contact-support', route('demo'));

        $this->get('/contact-support')->assertOk()->assertSee('Contact our support team');
        $this->get('/demo')->assertStatus(301)->assertRedirect('http://localhost/contact-support');

        // A page opened before the change still posts to the old address.
        $this->post('/demo-request', ['name' => 'Old Page', 'phone' => '9876543210'])->assertSessionHasErrors('email');
    }
}
