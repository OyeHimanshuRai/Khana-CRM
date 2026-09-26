<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The counter, over HTTP.
 *
 * The service tests already prove the arithmetic. What they cannot see is the
 * shape of what the till actually posts - and the till posts a rate box on
 * every line whether anyone touched it or not. This class exists because a
 * guard that read "a price was submitted" as "a price was changed" locked
 * ordinary cashiers out of every sale, and no service test could have noticed.
 */
class PosTerminalTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries a sub-path, which would prefix every request here
        // and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();
        $this->stock = new StockService();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    /**
     * A cashier: the terminal and nothing that rewrites what they billed.
     *
     * @param  list<string>  $extra
     */
    private function cashier(array $extra = []): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Counter Staff '.$counter,
            'email' => "cashier{$counter}@example.test",
            'password' => 'cashier-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(array_merge(
            ['pos.terminal.view', 'pos.terminal.create', 'pos.terminal.print'],
            $extra,
        ));

        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    /**
     * Priced tax-inclusive at 18%, so the shelf price and the counter price
     * are the same number and the assertions stay readable.
     */
    private function product(array $attributes = [], float $stock = 100): Product
    {
        static $counter = 0;
        $counter++;

        $product = Product::create([
            'name' => 'Till Product '.$counter,
            'slug' => Product::uniqueSlug('Till Product '.$counter),
            'sku' => Product::generateSku('TILL'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 400,
            'mrp' => 600,
            'selling_price' => 590,
            'tax_inclusive' => true,
            ...$attributes,
        ]);

        if ($stock > 0) {
            $this->stock->receive(
                $product, $stock, $this->warehouse, null, 400,
                StockMovement::PURCHASE, null, null, $this->shop->id
            );
        }

        return $product;
    }

    /**
     * What the terminal posts: the rate box is always filled in, because a
     * readonly input still submits.
     *
     * @return array<string, mixed>
     */
    private function sale(Product $product, array $overrides = []): array
    {
        $price = $product->counterPriceFor($this->shop->id);

        return [
            'channel' => Invoice::POS,
            'warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => $price,
                ],
            ],
            'payments' => [
                ['method' => Payment::CASH, 'amount' => $price * 2],
            ],
            ...$overrides,
        ];
    }

    /** Named ring(), not post(), because TestCase::post() is already taken. */
    private function ring(User $user, array $payload)
    {
        return $this->actingAs($user)->postJson('/admin/pos', $payload);
    }

    /* --------------------------------------------- the ordinary sale works */

    public function test_a_cashier_without_the_discount_right_can_still_sell(): void
    {
        $product = $this->product();

        $response = $this->ring($this->cashier(), $this->sale($product));

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, Invoice::allShops()->count());
    }

    public function test_the_rate_box_being_posted_is_not_a_discount(): void
    {
        $product = $this->product();

        // Exactly the catalogue price, which is what the till prefills.
        $this->ring($this->cashier(), $this->sale($product))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_a_tax_exclusive_product_bills_at_its_grossed_up_price(): void
    {
        // 500 + 18% = 590 at the counter. The till posts 590; the guard must
        // gross the catalogue's 500 up before comparing, or every sale of a
        // tax-exclusive product looks like a discount.
        $product = $this->product(['selling_price' => 500, 'tax_inclusive' => false]);

        $this->assertSame(590.0, $product->counterPriceFor($this->shop->id));

        $this->ring($this->cashier(), $this->sale($product))
            ->assertOk()
            ->assertJsonPath('success', true);

        $invoice = Invoice::allShops()->firstOrFail();

        $this->assertEqualsWithDelta(1180.0, (float) $invoice->grand_total, 0.01);
    }

    public function test_rounding_noise_in_the_posted_price_is_not_a_discount(): void
    {
        $product = $this->product();

        $payload = $this->sale($product);
        $payload['items'][0]['unit_price'] = $product->counterPriceFor($this->shop->id) - 0.004;

        $this->ring($this->cashier(), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_charging_above_the_shelf_price_is_not_a_discount(): void
    {
        $product = $this->product();

        $payload = $this->sale($product);
        $payload['items'][0]['unit_price'] = 700;
        $payload['payments'][0]['amount'] = 1400;

        $this->ring($this->cashier(), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /* ------------------------------------------------- the guard still bites */

    public function test_cutting_the_price_needs_the_discount_right(): void
    {
        $product = $this->product();

        $payload = $this->sale($product);
        $payload['items'][0]['unit_price'] = 500;
        $payload['payments'][0]['amount'] = 1000;

        $this->ring($this->cashier(), $payload)
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'supervisor'));

        $this->assertSame(0, Invoice::allShops()->count());
    }

    public function test_a_line_discount_needs_the_discount_right(): void
    {
        $product = $this->product();

        $payload = $this->sale($product);
        $payload['items'][0]['discount_percent'] = 10;

        $this->ring($this->cashier(), $payload)->assertStatus(403);
    }

    public function test_an_invoice_discount_needs_the_discount_right(): void
    {
        $product = $this->product();

        $payload = $this->sale($product);
        $payload['invoice_discount'] = 50;

        $this->ring($this->cashier(), $payload)->assertStatus(403);
    }

    public function test_a_supervisor_may_cut_the_price(): void
    {
        $product = $this->product();

        $payload = $this->sale($product);
        $payload['items'][0]['unit_price'] = 500;
        $payload['payments'][0]['amount'] = 1000;

        $this->ring($this->cashier(['pos.terminal.discount']), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $invoice = Invoice::allShops()->firstOrFail();

        $this->assertEqualsWithDelta(1000.0, (float) $invoice->grand_total, 0.01);
    }

    /* ------------------------------------------------------------- credit */

    public function test_leaving_a_bill_unpaid_needs_the_credit_right(): void
    {
        $product = $this->product();

        $customer = Customer::create([
            'shop_id' => $this->shop->id,
            'name' => 'Ramesh Patil',
            'phone' => '9800000001',
            'allow_credit' => true,
            'credit_limit' => 50000,
            'credit_days' => 21,
        ]);

        $payload = $this->sale($product, [
            'customer_id' => $customer->id,
            'payments' => [],
            'is_credit' => true,
        ]);

        $this->ring($this->cashier(), $payload)
            ->assertStatus(403)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'credit sale right'));

        $this->ring($this->cashier(['pos.terminal.credit']), $payload)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /* ------------------------------------------------------------ the screen */

    public function test_the_terminal_hides_the_rate_box_from_a_plain_cashier(): void
    {
        $this->product();

        $this->actingAs($this->cashier())
            ->get('/admin/pos')
            ->assertOk()
            ->assertSee('readonly', false);
    }
}
