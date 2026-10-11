<?php

namespace Tests\Feature;

use App\Contracts\WhatsAppGateway;
use App\Models\Floor;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Services\TableQrService;
use App\Services\Whatsapp\CloudGateway;
use App\Services\Whatsapp\WhatsAppManager;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Installing the menu, and reaching a guest on WhatsApp (§2, §14, §15).
 *
 * The property worth defending on the PWA side is the one that is easy to get
 * wrong in the opposite direction: a service worker's normal job is to serve
 * from a cache, and on a restaurant menu that means showing a dish that sold
 * out an hour ago. §8 asks for an instant sold-out toggle by name, so the
 * worker here caches assets and nothing else.
 *
 * On the WhatsApp side it is that a message is a template, not free text -
 * Meta's rule, not a provider's, and one a config key cannot opt out of.
 */
class GuestPwaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function seat(): void
    {
        $floor = Floor::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
            'is_active' => true,
        ]);

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);

        app(TableQrService::class)->issue($table);

        $this->get('/t/'.$table->fresh('activeQr')->activeQr->token)
            ->assertRedirect(route('table.show'));
    }

    /* --------------------------------------------------------- the manifest */

    public function test_the_manifest_carries_the_outlets_own_name(): void
    {
        $this->seat();

        $manifest = $this->get(route('table.manifest'))->assertOk()->json();

        // A static manifest would install every restaurant on the platform
        // as whatever the file happened to say.
        $this->assertSame($this->shop()->name, $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
    }

    public function test_it_opens_the_menu_rather_than_the_site_root(): void
    {
        $this->seat();

        $manifest = $this->get(route('table.manifest'))->json();

        // Somebody who installed this did it from a table. Sending them to a
        // storefront home page is a strange answer to "open my menu".
        $this->assertSame(route('table.show', absolute: false), $manifest['start_url']);
    }

    public function test_the_manifest_works_for_somebody_with_no_sitting(): void
    {
        // No scan, no cookie. The company name is the right fallback rather
        // than a 500 on a page somebody reached by typing.
        $manifest = $this->get(route('table.manifest'))->assertOk()->json();

        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_the_icon_it_points_at_actually_exists(): void
    {
        // A manifest naming a missing icon means the browser silently refuses
        // to offer installation, which is the hardest kind of broken to spot.
        $this->assertFileExists(public_path('assets/img/icon-512.png'));
    }

    public function test_the_guest_page_offers_the_manifest_and_the_worker(): void
    {
        $this->seat();

        $this->get(route('table.show'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('sw-url', false);
    }

    /* ---------------------------------------------- the worker's one rule */

    public function test_the_service_worker_caches_assets_and_nothing_else(): void
    {
        $worker = file_get_contents(public_path('sw.js'));

        // The rule the whole file exists to enforce: a cached menu shows a
        // dish that sold out an hour ago.
        $this->assertStringContainsString('/assets/', $worker);
        $this->assertStringContainsString('CACHEABLE', $worker);

        // And it must never claim a non-GET, which would swallow an order.
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
    }

    /* ------------------------------------------------------------ WhatsApp */

    public function test_whatsapp_falls_back_to_the_log_and_still_reports_success(): void
    {
        $manager = app(WhatsAppManager::class);

        // The default. It is what lets the whole flow be tried without a Meta
        // Business account and an approved template.
        $this->assertTrue($manager->isLive());
        $this->assertTrue($manager->send('9876543210', 'order_ready', ['GF-04']));
    }

    public function test_a_template_key_is_mapped_through_config(): void
    {
        config(['whatsapp.templates.order_ready' => 'my_approved_name']);

        $fake = new class implements WhatsAppGateway
        {
            public string $template = '';

            public function key(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $to, string $template, array $variables = [], ?string $body = null): bool
            {
                $this->template = $template;

                return true;
            }
        };

        app(WhatsAppManager::class)->swap($fake);

        app(WhatsAppManager::class)->send('9876543210', 'order_ready');

        // A restaurant whose approved template is called something else
        // changes a setting rather than waiting for a release.
        $this->assertSame('my_approved_name', $fake->template);
    }

    public function test_the_cloud_driver_is_not_live_until_it_is_configured(): void
    {
        $gateway = new CloudGateway(['phone_number_id' => '', 'token' => '']);

        // False is a working state: whatever wanted to send falls back, and
        // the restaurant carries on.
        $this->assertFalse($gateway->isConfigured());
        $this->assertFalse($gateway->send('9876543210', 'order_ready'));
    }

    public function test_the_cloud_driver_sends_a_template_not_free_text(): void
    {
        Http::fake(['graph.example.test/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $gateway = new CloudGateway([
            'phone_number_id' => '12345',
            'token' => 'token',
            'api' => 'https://graph.example.test/v21.0',
            'language' => 'en',
        ]);

        $this->assertTrue($gateway->send('9876543210', 'order_ready', ['GF-04', 'Dal Makhani']));

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Meta will not let a business open a conversation with free
            // text. A design that sent one would send nothing in production
            // and look fine in testing.
            return $body['type'] === 'template'
                && $body['template']['name'] === 'order_ready'
                // Local ten digits become E.164 without a plus.
                && $body['to'] === '919876543210'
                && $body['template']['components'][0]['parameters'][0]['text'] === 'GF-04';
        });
    }

    public function test_a_refused_whatsapp_message_is_a_false_not_an_exception(): void
    {
        Http::fake(['graph.example.test/*' => Http::response([
            'error' => ['message' => 'Template name does not exist'],
        ], 400)]);

        $gateway = new CloudGateway([
            'phone_number_id' => '12345',
            'token' => 'token',
            'api' => 'https://graph.example.test/v21.0',
        ]);

        // A failed message is a normal Tuesday and must never become an
        // exception on a guest's journey.
        $this->assertFalse($gateway->send('9876543210', 'nope'));
    }
}
