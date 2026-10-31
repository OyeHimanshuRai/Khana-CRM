<?php

namespace Tests\Feature;

use App\Contracts\SmsGateway;
use App\Models\VerificationCode;
use App\Services\OtpService;
use App\Services\Sms\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The line that lets a phone fill a one-time code in by itself.
 *
 * A browser reads a code out of an SMS (the WebOTP API) only when the message
 * ENDS with "@your-domain #123456". That line is opt-in: a bulk-SMS template in
 * India is registered in advance, and an extra line makes it a different
 * template, so the default must be a message that is exactly what it always was.
 */
class OtpOriginLineTest extends TestCase
{
    use RefreshDatabase;

    private OtpCaptureSms $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Http::preventStrayRequests();

        $this->sms = new OtpCaptureSms;
        app(SmsManager::class)->swap($this->sms);

        config(['app.url' => 'https://restro.example.com']);
    }

    private function send(): string
    {
        app(OtpService::class)->send('9876543210', VerificationCode::TABLE_SESSION, null, 'Spice Route');

        return $this->sms->last;
    }

    public function test_by_default_the_message_is_exactly_what_it_always_was(): void
    {
        config(['sms.otp.origin_line' => false]);

        $message = $this->send();

        $this->assertStringContainsString('is your verification code for Spice Route', $message);
        $this->assertStringNotContainsString('@restro.example.com', $message);
    }

    public function test_when_switched_on_the_last_line_binds_the_code_to_the_site(): void
    {
        config(['sms.otp.origin_line' => true]);

        $message = $this->send();

        // The code in the sentence is the code in the origin line.
        preg_match('/^(\d{6}) is your verification code/', $message, $in);
        $lines = explode("\n", trim($message));

        $this->assertNotEmpty($in, 'the sentence still leads with the code');
        $this->assertSame('@restro.example.com #'.$in[1], end($lines));
    }
}

class OtpCaptureSms implements SmsGateway
{
    public string $last = '';

    public function key(): string
    {
        return 'capture';
    }

    public function label(): string
    {
        return 'Capture';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(string $to, string $message): bool
    {
        $this->last = $message;

        return true;
    }
}
