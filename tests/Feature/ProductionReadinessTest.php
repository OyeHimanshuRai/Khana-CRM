<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Services\SystemHealthService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The settings that decide whether an install is safe to put online, and
 * the warnings that say so before a customer finds out.
 */
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
    }

    /** @return array<string, array<string, mixed>> */
    private function checks(): array
    {
        return collect(app(SystemHealthService::class)->environment())->keyBy('label')->all();
    }

    public function test_every_page_says_what_may_load_into_it_and_who_may_frame_it(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_health_warns_when_the_session_cookie_could_travel_over_plain_http(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://restro.example.com', 'session.secure' => null]);

        $this->assertSame('danger', $this->checks()['Secure session cookie']['tone']);

        config(['session.secure' => true]);
        $this->assertSame('success', $this->checks()['Secure session cookie']['tone']);
    }

    public function test_health_warns_when_lapsed_owners_cannot_pay_online(): void
    {
        config(['payments.platform.key' => null, 'payments.platform.secret' => null]);
        app()->forgetInstance(\App\Services\Payments\GatewayManager::class);

        $check = $this->checks()['Online renewals'];
        $this->assertSame('warning', $check['tone']);
        $this->assertStringContainsString('renewal request', $check['note']);

        config(['payments.platform.key' => 'rzp_live_x', 'payments.platform.secret' => 'y']);
        app()->forgetInstance(\App\Services\Payments\GatewayManager::class);
        $this->assertSame('success', $this->checks()['Online renewals']['tone']);
    }

    public function test_the_outlet_form_warns_plainly_when_table_orders_need_no_code(): void
    {
        $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $shop->forceFill(['requires_otp' => false])->saveQuietly();
        CurrentShop::forget();

        $this->actingAs(User::query()->where('email', 'admin@erp.test')->firstOrFail())
            ->get(route('admin.shops.edit', $shop))
            ->assertOk()
            ->assertSee('anyone with a photo of a table’s QR code can send an order to the kitchen', false);
    }
}
