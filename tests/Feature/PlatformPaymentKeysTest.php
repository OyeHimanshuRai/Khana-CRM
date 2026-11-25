<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Payments\GatewayManager;
use App\Support\PaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Razorpay keys saved in Settings > Online Payment also switch on the platform's own
 * billing (paying for a plan, "Pay now" at registration), even while the guests' payment
 * provider is left on "Pay at the counter".
 */
class PlatformPaymentKeysTest extends TestCase
{
    use RefreshDatabase;

    private function reload(): GatewayManager
    {
        config(['payments.platform.key' => null, 'payments.platform.secret' => null, 'payments.platform.webhook_secret' => null]);
        Setting::flush();
        PaymentSettings::apply();
        app()->forgetInstance(GatewayManager::class);

        return app(GatewayManager::class);
    }

    public function test_keys_saved_in_settings_turn_on_platform_billing(): void
    {
        $this->assertFalse($this->reload()->platform()->isConfigured(), 'no keys, no online billing');

        // Entered on the Settings screen, with the provider still on "pay at the counter".
        Setting::put(['payment_gateway' => 'offline', 'razorpay_key' => 'rzp_test_abc', 'razorpay_secret' => 'sekret', 'razorpay_webhook_secret' => 'hook']);

        $manager = $this->reload();

        $this->assertTrue($manager->platform()->isConfigured());
        $this->assertSame('rzp_test_abc', config('payments.platform.key'));
        $this->assertSame('hook', config('payments.platform.webhook_secret'));
        // Guests at a table still pay at the counter: the provider choice is theirs.
        $this->assertFalse($manager->isLive());
    }

    public function test_the_registration_form_then_offers_pay_now(): void
    {
        Setting::put(['razorpay_key' => 'rzp_test_abc', 'razorpay_secret' => 'sekret']);
        $this->reload();
        $this->seed();

        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('Online payment is not available yet', $html);
        $this->assertStringContainsString('Secure online payment', $html);
    }
}
