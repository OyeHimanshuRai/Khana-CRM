<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\Tenant;
use App\Services\InvoiceService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Khana\Kit\Events\Inbox\InboxEvent;
use Khana\Kit\Events\Signature;
use Khana\Kit\Support\Ulid;
use Tests\Concerns\TalksToErp;
use Tests\TestCase;

/**
 * What ERP tells CRM (erp.availability.changed, erp.cost.changed), taken the
 * whole way in: a signed batch posted to /internal/events, stored by the
 * kit, checked against the copy of Accounts, and applied by CRM's handlers.
 */
class ErpInboxTest extends TestCase
{
    use RefreshDatabase, TalksToErp;

    private const SECRET = 'erp-to-crm-test-secret';

    private Shop $shop;

    /** @var array{tenant: string, outlet: string} */
    private array $ids;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        CurrentShop::forget();
        config(['khana.events.senders.erp' => self::SECRET]);

        $this->shop = Shop::query()->orderBy('id')->firstOrFail();
        $this->ids = $this->linkToAccounts($this->shop, ['crm' => 'active', 'erp' => 'active']);
    }

    /* ---------------------------------------------------------- fixtures */

    private function dish(string $name, float $price = 200): Product
    {
        return Product::create([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => $price,
            'purchase_price' => 50,
            'is_active' => true,
        ]);
    }

    /**
     * An ERP event as it travels, checked against its own contract first -
     * so these tests can never pass on a shape ERP would not send.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function erpEvent(string $type, array $data, ?string $outlet = null): array
    {
        $event = [
            'id' => Ulid::generate(),
            'type' => $type,
            'version' => 1,
            'source' => 'erp',
            'seq' => ++$this->seq,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'tenant_id' => $this->ids['tenant'],
            'outlet_id' => $outlet ?? $this->ids['outlet'],
            'data' => $data,
        ];

        $this->assertDataHonours($type, $data, $event['tenant_id'], $event['outlet_id'], 'erp');

        return $event;
    }

    private function deliver(array ...$events): TestResponse
    {
        $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/internal/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KHANA_SOURCE' => 'erp',
            'HTTP_X_KHANA_SIGNATURE' => Signature::sign($body, self::SECRET),
        ], $body);
    }

    private function statusOf(array $event): ?string
    {
        return InboxEvent::query()->where('event_id', $event['id'])->value('status');
    }

    private function availability(Product $product, bool $available, ?ProductVariant $variant = null): array
    {
        return $this->erpEvent('erp.availability.changed', [
            'item_id' => $product->fresh()->ulid,
            'variant_id' => $variant?->fresh()->ulid,
            'available' => $available,
            'reason' => $available ? 'restocked' : 'stock_out',
        ]);
    }

    private function cost(Product $product, int $paise, ?ProductVariant $variant = null): array
    {
        return $this->erpEvent('erp.cost.changed', [
            'item_id' => $product->fresh()->ulid,
            'variant_id' => $variant?->fresh()->ulid,
            'cost_paise' => $paise,
            'food_cost_percent' => '32.50',
        ]);
    }

    /* ------------------------------------------------------ availability */

    /*
     | The outlet is named in every read below: these tests run with nobody
     | signed in, so there is no shop in context, and whether stock can cover
     | a dish is one outlet's answer. Tests\Feature\OutletStockOutTest covers
     | what a second outlet sees.
     */

    public function test_a_stock_out_takes_the_dish_off_the_menu_and_a_restock_brings_it_back(): void
    {
        $dish = $this->dish('Prawn Curry');

        $out = $this->availability($dish, false);
        $this->deliver($out)->assertOk()->assertJson(['stored' => 1]);

        $this->assertSame(InboxEvent::APPLIED, $this->statusOf($out));
        $this->assertTrue($dish->fresh()->isSoldOut(null, $this->shop->id));
        $this->assertFalse($dish->fresh()->isOrderable(null, $this->shop->id));
        $this->assertFalse($dish->fresh()->is_sold_out, 'the hand flag is staff\'s; stock keeps its own row');

        $back = $this->availability($dish, true);
        $this->deliver($back)->assertOk();

        $this->assertSame(InboxEvent::APPLIED, $this->statusOf($back));
        $this->assertFalse($dish->fresh()->isSoldOut(null, $this->shop->id));
    }

    public function test_a_stock_out_for_one_size_switches_off_that_size_only(): void
    {
        $dish = $this->dish('Biryani');
        $half = ProductVariant::create(['product_id' => $dish->id, 'name' => 'Half', 'price' => 180]);
        $full = ProductVariant::create(['product_id' => $dish->id, 'name' => 'Full', 'price' => 320]);

        $this->deliver($this->availability($dish, false, $full))->assertOk();

        $this->assertFalse($full->fresh()->isAvailableAt($this->shop->id));
        $this->assertTrue($half->fresh()->isAvailableAt($this->shop->id));
        $this->assertTrue($full->fresh()->is_available, 'the switch staff throw for the size is left alone');
        $this->assertFalse($dish->fresh()->isSoldOut(null, $this->shop->id));
    }

    public function test_erps_own_updates_are_not_echoed_back_to_it(): void
    {
        $dish = $this->dish('Malai Kofta');
        $mark = $this->outboxMark();

        $this->deliver(
            $this->availability($dish, false),
            $this->cost($dish, 9000),
        )->assertOk();

        $this->assertTrue($dish->fresh()->isSoldOut(null, $this->shop->id));
        $this->assertCount(0, $this->outboxSince($mark), 'sold-out and cost are not in crm.menu.item_upserted');
    }

    /* -------------------------------------------------------------- cost */

    public function test_a_cost_change_becomes_the_cost_the_next_bill_books(): void
    {
        $dish = $this->dish('Paneer Tikka', 280);
        $full = ProductVariant::create(['product_id' => $dish->id, 'name' => 'Full', 'price' => 280]);

        $this->deliver(
            $this->cost($dish, 8125),
            $this->cost($dish, 11050, $full),
        )->assertOk();

        $this->assertSame('81.2500', $dish->fresh()->purchase_price);
        $this->assertSame('110.5000', $full->fresh()->purchase_price);

        $invoice = app(InvoiceService::class)->create([
            'shop' => $this->shop,
            'channel' => Invoice::POS,
            'items' => [
                ['product_id' => $dish->id, 'quantity' => 1],
                ['product_id' => $dish->id, 'product_variant_id' => $full->id, 'quantity' => 1],
            ],
            'payments' => [['amount' => 1000, 'method' => 'cash']],
        ]);

        $this->assertEqualsWithDelta(81.25, (float) $invoice->items[0]->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(110.50, (float) $invoice->items[1]->unit_cost, 0.0001, 'a size costs what ERP said that size costs');
        $this->assertSame($full->id, $invoice->items[1]->product_variant_id);
    }

    public function test_a_cost_change_lands_on_the_outlets_own_override_when_it_keeps_one(): void
    {
        $dish = $this->dish('Chicken Tikka', 320);
        $dish->shops()->attach($this->shop->id, ['purchase_price' => 70, 'is_active' => true]);

        $this->deliver($this->cost($dish, 9500))->assertOk();

        $this->assertEqualsWithDelta(95.0, $dish->fresh()->purchasePriceFor($this->shop->id), 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $dish->fresh()->purchase_price, 0.0001, 'the business-wide figure is left alone');
    }

    /* ---------------------------------------------------- what CRM lacks */

    public function test_an_item_crm_does_not_have_is_skipped_and_the_outlet_carries_on(): void
    {
        Log::spy();

        $dish = $this->dish('Dal Tadka');
        $half = ProductVariant::create(['product_id' => $dish->id, 'name' => 'Half', 'price' => 120]);
        $gone = $this->erpEvent('erp.availability.changed', [
            'item_id' => Ulid::generate(),
            'variant_id' => null,
            'available' => false,
            'reason' => 'stock_out',
        ]);
        $goneSize = $this->availability($dish, false, $half);
        $half->delete(); // a size taken off the form is deleted outright
        $after = $this->availability($dish, false);

        $this->deliver($gone, $goneSize, $after)->assertOk()->assertJson(['stored' => 3]);

        // Handled and passed over - not left waiting, not failed - so the
        // event behind it for the same outlet still went in.
        $this->assertSame(InboxEvent::APPLIED, $this->statusOf($gone));
        $this->assertSame(InboxEvent::APPLIED, $this->statusOf($goneSize));
        $this->assertSame(InboxEvent::APPLIED, $this->statusOf($after));
        $this->assertTrue($dish->fresh()->isSoldOut(null, $this->shop->id));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, "Skipped erp.availability.changed {$gone['id']}"))
            ->once();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, "Skipped erp.availability.changed {$goneSize['id']}"))
            ->once();
    }

    public function test_an_item_of_another_business_is_refused_and_the_outlet_waits(): void
    {
        $rival = Tenant::create(['name' => 'Rival Foods', 'code' => 'RIVAL', 'slug' => 'rival-foods']);
        $theirs = $this->dish('Their Special');
        DB::table('products')->where('id', $theirs->id)->update(['tenant_id' => $rival->id]);

        $wrong = $this->availability($theirs, false);
        $next = $this->availability($this->dish('Our Special'), false);

        $this->deliver($wrong, $next)->assertOk();

        $this->assertSame(InboxEvent::FAILED, $this->statusOf($wrong));
        $this->assertStringContainsString('TenantMismatch', (string) InboxEvent::query()->where('event_id', $wrong['id'])->value('error'));
        $this->assertSame(InboxEvent::PENDING, $this->statusOf($next), 'the outlet waits until somebody looks');
        $this->assertFalse($theirs->fresh()->is_sold_out);
        $this->assertSame(0, DB::table('product_stock_outs')->count(), 'and nothing was taken off anybody\'s menu');
    }
}
