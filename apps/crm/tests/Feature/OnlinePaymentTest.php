<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Services\PaymentIntentService;
use App\Services\Payments\GatewayManager;
use App\Services\Payments\OfflineGateway;
use App\Services\Payments\RazorpayGateway;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Online payment (§11).
 *
 * Everything here runs without touching a provider. The three things that
 * actually matter are all local:
 *
 *   1. **The signature is the whole check.** A browser can post any JSON it
 *      likes; the only thing it cannot forge is an HMAC of a secret it does
 *      not have.
 *
 *   2. **Capture happens once.** The browser callback and the provider's
 *      webhook race on every single payment, and a table settled twice is two
 *      invoices, two GST entries and a guest charged again.
 *
 *   3. **No keys is a working state.** A restaurant that has not set a
 *      provider up takes cash, and nothing on the guest's page pretends
 *      otherwise.
 */
class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rzp_test_key';

    private const SECRET = 'rzp_test_secret';

    private const WEBHOOK_SECRET = 'rzp_webhook_secret';

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

    /* ---------------------------------------------------------- the gateway */

    /** Put a configured Razorpay in front of the container's manager. */
    private function live(): RazorpayGateway
    {
        $gateway = new RazorpayGateway([
            'label' => 'Pay now',
            'key' => self::KEY,
            'secret' => self::SECRET,
            'webhook_secret' => self::WEBHOOK_SECRET,
            'currency' => 'INR',
            'checkout_js' => 'https://example.test/checkout.js',
            'api' => 'https://api.example.test/v1',
        ]);

        app(GatewayManager::class)->swap($gateway);

        return $gateway;
    }

    private function offline(): void
    {
        app(GatewayManager::class)->swap(new OfflineGateway());
    }

    /** What the provider says when an order is opened. */
    private function fakeOrder(string $id = 'order_TEST123'): void
    {
        Http::fake([
            'api.example.test/*' => Http::response([
                'id' => $id,
                'amount' => 18000,
                'currency' => 'INR',
                'status' => 'created',
            ], 200),
        ]);
    }

    /* ---------------------------------------------------------- a table owing */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function seated(float $price = 60, int $quantity = 3): TableSession
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();

        app(TableQrService::class)->issue($table);
        $table = $table->fresh(['activeQr']);

        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        $session = TableSession::allShops()
            ->live()
            ->where('restaurant_table_id', $table->id)
            ->firstOrFail();

        $dish = new Product([
            'name' => 'Butter Naan',
            'slug' => Product::uniqueSlug('Butter Naan'),
            'sku' => Product::generateSku('Butter Naan'),
            'selling_price' => $price,
            'is_active' => true,
            'is_made_to_order' => true,
        ]);
        $dish->save();

        if ($quantity > 0) {
            app(TableCartService::class)->add($session, $dish, null, $quantity);
            app(TableOrderService::class)->place($session);
        }

        return $session->fresh();
    }

    /** The signature Razorpay would have sent for this pair. */
    private function sign(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, self::SECRET);
    }

    /* --------------------------------------------------------- not set up */

    public function test_without_keys_the_guest_is_told_to_pay_at_the_counter(): void
    {
        $this->offline();

        $session = $this->seated();

        $this->get('/t/'.$session->table->activeQr->token);

        $this->get('/t/orders')
            ->assertOk()
            ->assertSee('Ask a member of staff')
            // Nothing is stubbed: there is no button that does nothing.
            ->assertDontSee('data-pay-button', false);
    }

    public function test_starting_a_payment_without_keys_is_refused(): void
    {
        $this->offline();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);

        $this->postJson('/t/pay')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /* ------------------------------------------------------------ opening */

    public function test_a_guest_opens_a_checkout_for_what_the_table_owes(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);

        $this->postJson('/t/pay')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 180)
            ->assertJsonPath('data.checkout.order_id', 'order_TEST123');

        $intent = PaymentIntent::allShops()->firstOrFail();

        $this->assertSame(180.0, (float) $intent->amount);
        $this->assertSame(PaymentIntent::PENDING, $intent->status);
        $this->assertSame($session->id, (int) $intent->payable_id);
    }

    /**
     * A guest who went to fetch their card meets the same provider order.
     *
     * A fresh one per tap would leave a trail of abandoned orders in the
     * provider's dashboard and make reconciliation a guess.
     */
    public function test_tapping_pay_twice_resumes_the_same_attempt(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);

        $this->postJson('/t/pay')->assertOk();
        $this->postJson('/t/pay')->assertOk();

        $this->assertSame(1, PaymentIntent::allShops()->count());
    }

    /* --------------------------------------------------------- the signature */

    public function test_a_forged_callback_is_refused(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);
        $this->postJson('/t/pay')->assertOk();

        $intent = PaymentIntent::allShops()->firstOrFail();

        $this->postJson('/t/pay/confirm', [
            'reference' => $intent->reference,
            'razorpay_order_id' => $intent->provider_order_id,
            'razorpay_payment_id' => 'pay_FAKE',
            'razorpay_signature' => 'not-a-real-signature',
        ])->assertStatus(422);

        $this->assertSame(PaymentIntent::FAILED, $intent->fresh()->status);
        $this->assertSame(0, Invoice::allShops()->count());
    }

    /**
     * The signature has to name *this* intent's order.
     *
     * Otherwise a guest could replay another table's perfectly valid callback
     * and settle their own bill with somebody else's payment.
     */
    public function test_a_signature_for_another_order_is_refused(): void
    {
        $this->live();

        $gateway = $this->live();
        $session = $this->seated();

        $intent = new PaymentIntent([
            'shop_id' => $session->shop_id,
            'payable_type' => $session->getMorphClass(),
            'payable_id' => $session->id,
            'reference' => PaymentIntent::freshReference(),
            'provider' => 'razorpay',
            'provider_order_id' => 'order_MINE',
            'amount' => 180,
            'status' => PaymentIntent::PENDING,
        ]);
        $intent->save();

        // A genuine signature - for somebody else's order.
        $this->assertFalse($gateway->verify($intent, [
            'razorpay_order_id' => 'order_THEIRS',
            'razorpay_payment_id' => 'pay_1',
            'razorpay_signature' => $this->sign('order_THEIRS', 'pay_1'),
        ]));
    }

    /* ------------------------------------------------------------ capture */

    public function test_a_verified_payment_settles_the_table(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);
        $this->postJson('/t/pay')->assertOk();

        $intent = PaymentIntent::allShops()->firstOrFail();

        $this->postJson('/t/pay/confirm', [
            'reference' => $intent->reference,
            'razorpay_order_id' => $intent->provider_order_id,
            'razorpay_payment_id' => 'pay_GOOD',
            'razorpay_signature' => $this->sign($intent->provider_order_id, 'pay_GOOD'),
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);

        // The same path the counter uses: one invoice, paid in full.
        $invoice = Invoice::allShops()->where('table_session_id', $session->id)->firstOrFail();

        $this->assertSame(180.0, (float) $invoice->grand_total);
        $this->assertSame(180.0, (float) $invoice->paid_total);

        // And the party has left.
        $this->assertSame(TableSession::CLOSED, $session->fresh()->status);
    }

    /**
     * The property the whole design is arranged around.
     *
     * The browser callback and the webhook race on every payment. Settling
     * twice is two invoices, two GST entries and a guest charged again.
     */
    public function test_the_browser_and_the_webhook_together_settle_once(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);
        $this->postJson('/t/pay')->assertOk();

        $intent = PaymentIntent::allShops()->firstOrFail();

        // The browser gets there first.
        $this->postJson('/t/pay/confirm', [
            'reference' => $intent->reference,
            'razorpay_order_id' => $intent->provider_order_id,
            'razorpay_payment_id' => 'pay_GOOD',
            'razorpay_signature' => $this->sign($intent->provider_order_id, 'pay_GOOD'),
        ])->assertOk();

        // Then the provider's webhook, saying the same thing.
        $this->postWebhook($intent->provider_order_id, 'pay_GOOD', 18000)->assertOk();

        $this->assertSame(1, Invoice::allShops()->where('table_session_id', $session->id)->count());
    }

    /** And the other way round: the webhook alone is enough. */
    public function test_the_webhook_alone_settles_the_table(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);
        $this->postJson('/t/pay')->assertOk();

        $intent = PaymentIntent::allShops()->firstOrFail();

        // The guest's phone died before the redirect.
        $this->postWebhook($intent->provider_order_id, 'pay_GOOD', 18000)->assertOk();

        $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);
        $this->assertSame(1, Invoice::allShops()->where('table_session_id', $session->id)->count());
        $this->assertSame(TableSession::CLOSED, $session->fresh()->status);
    }

    /* ------------------------------------------------------------ webhooks */

    public function test_an_unsigned_webhook_changes_nothing_and_still_answers_200(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);
        $this->postJson('/t/pay')->assertOk();

        $intent = PaymentIntent::allShops()->firstOrFail();

        $body = $this->webhookBody($intent->provider_order_id, 'pay_FORGED', 18000);

        /*
         | 200 on purpose. A provider retries a non-2xx for hours, and an
         | endpoint that errored on rubbish would spend a day being hammered
         | with it while the genuine events queued behind.
         */
        $this->call('POST', '/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => 'nonsense',
        ], $body)->assertOk();

        $this->assertSame(PaymentIntent::PENDING, $intent->fresh()->status);
        $this->assertSame(0, Invoice::allShops()->count());
    }

    /**
     * A webhook claiming a different amount is not acted on.
     *
     * Two rupees against a nine-hundred-rupee bill is either a
     * misconfiguration or an attack, and settling on it would give a table
     * away.
     */
    public function test_a_webhook_for_the_wrong_amount_is_ignored(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);
        $this->postJson('/t/pay')->assertOk();

        $intent = PaymentIntent::allShops()->firstOrFail();

        $this->postWebhook($intent->provider_order_id, 'pay_CHEAP', 200)->assertOk();

        $this->assertSame(PaymentIntent::PENDING, $intent->fresh()->status);
        $this->assertSame(0, Invoice::allShops()->count());
    }

    public function test_an_event_this_system_ignores_does_nothing(): void
    {
        $this->live();

        $body = json_encode([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_X', 'order_id' => 'order_X']]],
        ]);

        $this->call('POST', '/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body)->assertOk();

        $this->assertSame(0, PaymentIntent::allShops()->count());
    }

    /* ------------------------------------------------------- the guardrails */

    public function test_a_reference_from_another_table_is_refused(): void
    {
        $this->live();
        $this->fakeOrder();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);
        $this->postJson('/t/pay')->assertOk();

        $intent = PaymentIntent::allShops()->firstOrFail();

        // Somebody else's sitting.
        $intent->forceFill(['payable_id' => $session->id + 999])->save();

        $this->postJson('/t/pay/confirm', [
            'reference' => $intent->reference,
            'razorpay_order_id' => $intent->provider_order_id,
            'razorpay_payment_id' => 'pay_GOOD',
            'razorpay_signature' => $this->sign($intent->provider_order_id, 'pay_GOOD'),
        ])->assertStatus(404);
    }

    public function test_paying_needs_a_sitting(): void
    {
        $this->live();

        // No scan, so no cookie.
        $this->postJson('/t/pay')->assertStatus(419);
    }

    public function test_a_table_that_has_ordered_nothing_cannot_be_paid_for(): void
    {
        $this->live();

        // Seated, but nothing sent to the kitchen yet.
        $session = $this->seated(60, 0);
        $this->get('/t/'.$session->table->activeQr->token);

        $this->postJson('/t/pay')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * A table settled at the counter sends its guest back to the QR.
     *
     * The sitting is closed, so the cookie no longer resolves - which is the
     | same rule that stops a guest ordering against a bill that has been
     * printed, and it is right that paying meets it too.
     */
    public function test_a_settled_table_sends_the_guest_back_to_the_code(): void
    {
        $this->live();

        $session = $this->seated();
        $this->get('/t/'.$session->table->activeQr->token);

        app(\App\Services\TableBillService::class)->settle($session, [
            'payments' => [['method' => 'cash', 'amount' => 180]],
        ]);

        $this->postJson('/t/pay')
            ->assertStatus(419)
            ->assertJsonPath('success', false);
    }

    /* ------------------------------------------------------------- helpers */

    private function webhookBody(string $orderId, string $paymentId, int $paise): string
    {
        return json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => [
                    'entity' => [
                        'id' => $paymentId,
                        'order_id' => $orderId,
                        'status' => 'captured',
                        'amount' => $paise,
                    ],
                ],
            ],
        ]);
    }

    private function postWebhook(string $orderId, string $paymentId, int $paise)
    {
        $body = $this->webhookBody($orderId, $paymentId, $paise);

        return $this->call('POST', '/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
        ], $body);
    }
}
