<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\OutletSetting;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\Purchasing;
use App\Services\StockService;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Buying: an order, a delivery, the bill it raises and paying it off.
 *
 * Each step moves the document, the stock and the money together, and what
 * is owed always adds up.
 */
class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{tenant: string, outlet: string} */
    private array $at;

    private Store $main;

    private Purchasing $purchasing;

    private Supplier $dairy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->at = $this->business();
        $this->main = Outlets::stores($this->at['outlet'])['main'];
        $this->purchasing = app(Purchasing::class);
        $this->dairy = Supplier::allTenants()->create(['tenant_id' => $this->at['tenant'], 'name' => 'Amul Dairy', 'terms_days' => 15]);
    }

    /** One line as App\Support\Lines hands it over. */
    private function line(StockItem $item, string|int $amount, string $unit, int $rupees, array $extra = []): array
    {
        return array_merge([
            'item' => $item,
            'qty' => Units::toBase($amount, $unit, $item->base_unit),
            'entered_qty' => (string) $amount,
            'unit' => $unit,
            'amount_paise' => $rupees * 100,
            'batch_no' => null,
            'expiry' => null,
        ], $extra);
    }

    private function delivery(array $extra = []): array
    {
        return array_merge(['store' => $this->main, 'supplier' => $this->dairy, 'received_on' => '2026-10-01', 'bill_no' => 'AD/118'], $extra);
    }

    public function test_a_delivery_books_the_stock_and_raises_the_bill(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 0, ['gst_rate_bp' => 500]);
        $rice = $this->stockItem($this->at['tenant'], 'Rice', 'g');

        $receipt = $this->purchasing->receive($this->at['outlet'], $this->delivery(), [
            $this->line($paneer, 10, 'kg', 4000),
            $this->line($rice, 25, 'kg', 2500),
        ]);

        $this->assertSame('GRN-0001', $receipt->number);
        $this->assertSame(650_000, $receipt->net_paise);
        $this->assertSame(20_000, $receipt->tax_paise, '5% on the paneer only');
        $this->assertSame(670_000, $receipt->total_paise);

        $stock = app(StockService::class);
        $this->assertSame(Units::toBase(10, 'kg', 'g'), $stock->onHand($this->at['outlet'], $paneer, $this->main));

        // A restaurant cannot claim input GST, so it is part of the cost:
        // Rs 4,200 for ten kilos is Rs 420 a kilo.
        $this->assertSame(Units::rateFromQuote(420, 'g'), $stock->costRate($this->at['outlet'], $paneer));
        $this->assertSame(Units::rateFromQuote(100, 'g'), $stock->costRate($this->at['outlet'], $rice));

        $movement = StockMovement::allOutlets()->where('stock_item_id', $paneer->id)->sole();
        $this->assertSame($receipt->getMorphClass(), $movement->reference_type);
        $this->assertSame($receipt->id, $movement->reference_id);

        $bill = $receipt->bill;
        $this->assertSame('AD/118', $bill->bill_no);
        $this->assertSame(670_000, $bill->total_paise);
        $this->assertSame('2026-10-16', $bill->due_on->toDateString(), 'fifteen days after the delivery');
        $this->assertSame(SupplierBill::OPEN, $bill->status);
        $this->assertSame(670_000, $this->purchasing->owed($this->at['outlet'], $this->dairy));
    }

    public function test_an_outlet_that_claims_input_tax_keeps_it_out_of_the_cost(): void
    {
        OutletSetting::create(['outlet_id' => $this->at['outlet'], 'tenant_id' => $this->at['tenant'], 'input_tax_is_cost' => false]);
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g', 0, ['gst_rate_bp' => 500]);

        $receipt = $this->purchasing->receive($this->at['outlet'], $this->delivery(), [$this->line($paneer, 10, 'kg', 4000)]);

        $this->assertSame(Units::rateFromQuote(400, 'g'), app(StockService::class)->costRate($this->at['outlet'], $paneer));
        // The supplier is still owed the tax.
        $this->assertSame(420_000, $receipt->bill->total_paise);
    }

    public function test_an_item_that_goes_off_arrives_as_a_batch(): void
    {
        $curd = $this->stockItem($this->at['tenant'], 'Curd', 'g', 0, ['track_expiry' => true, 'shelf_life_days' => 5]);

        $receipt = $this->purchasing->receive($this->at['outlet'], $this->delivery(), [
            $this->line($curd, 5, 'kg', 400, ['batch_no' => 'CRD-7', 'expiry' => '2026-10-04']),
            $this->line($curd, 5, 'kg', 400),
        ]);

        $batches = Batch::allOutlets()->orderBy('id')->get();
        $this->assertSame(['CRD-7', $receipt->number], $batches->pluck('batch_no')->all());
        $this->assertSame('2026-10-04', $batches[0]->expiry_date->toDateString());
        // No date typed: the item's shelf life from the day it arrived.
        $this->assertSame('2026-10-06', $batches[1]->expiry_date->toDateString());
    }

    public function test_a_delivery_is_ticked_off_against_its_order(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g');
        $curd = $this->stockItem($this->at['tenant'], 'Curd', 'g');

        $order = $this->purchasing->order($this->at['outlet'], $this->dairy, '2026-09-30', '2026-10-01', [
            $this->line($paneer, 10, 'kg', 4000),
            $this->line($curd, 5, 'kg', 400),
        ]);

        $this->assertSame('PO-0001', $order->number);
        $this->assertSame(440_000, $order->total_paise);

        $this->purchasing->receive($this->at['outlet'], $this->delivery(['order' => $order]), [$this->line($paneer, 6, 'kg', 2400)]);
        $this->assertSame(PurchaseOrder::PART_RECEIVED, $order->fresh()->status);

        $this->purchasing->receive($this->at['outlet'], $this->delivery(['order' => $order, 'bill_no' => 'AD/119']), [
            $this->line($paneer, 4, 'kg', 1600),
            $this->line($curd, 5, 'kg', 400),
        ]);
        $this->assertSame(PurchaseOrder::RECEIVED, $order->fresh()->status);

        $this->expectException(RuntimeException::class);
        $this->purchasing->cancelOrder($order->fresh());
    }

    public function test_a_payment_settles_the_oldest_bills_first_and_keeps_the_rest_as_an_advance(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g');

        $first = $this->purchasing->receive($this->at['outlet'], $this->delivery(['received_on' => '2026-09-20', 'bill_no' => 'AD/1']), [$this->line($paneer, 10, 'kg', 4000)])->bill;
        $second = $this->purchasing->receive($this->at['outlet'], $this->delivery(['received_on' => '2026-09-25', 'bill_no' => 'AD/2']), [$this->line($paneer, 5, 'kg', 2000)])->bill;

        // Rs 5,000 against Rs 6,000 owed: all of the first, half the second.
        $payments = $this->purchasing->pay($this->at['outlet'], $this->dairy, 500_000, 'upi', '2026-10-01', reference: 'UTR123');

        $this->assertSame([[$first->id, 400_000], [$second->id, 100_000]], $payments->map(fn (SupplierPayment $p) => [$p->supplier_bill_id, $p->amount_paise])->all());
        $this->assertSame(SupplierBill::PAID, $first->fresh()->status);
        $this->assertSame(100_000, $second->fresh()->duePaise());
        $this->assertSame(100_000, $this->purchasing->owed($this->at['outlet'], $this->dairy));

        // Paying a named bill goes to that bill; overpaying leaves an advance.
        $payments = $this->purchasing->pay($this->at['outlet'], $this->dairy, 150_000, 'cash', '2026-10-02', $second->fresh());

        $this->assertSame([[$second->id, 100_000], [null, 50_000]], $payments->map(fn (SupplierPayment $p) => [$p->supplier_bill_id, $p->amount_paise])->all());
        $this->assertSame(-50_000, $this->purchasing->owed($this->at['outlet'], $this->dairy), 'the dairy now owes the outlet');
    }

    public function test_goods_sent_back_leave_stock_and_come_off_what_is_owed(): void
    {
        $paneer = $this->stockItem($this->at['tenant'], 'Paneer', 'g');
        $this->purchasing->receive($this->at['outlet'], $this->delivery(), [$this->line($paneer, 10, 'kg', 4000)]);

        $credit = $this->purchasing->sendBack($this->at['outlet'], $this->dairy, $this->main, $paneer, 0, Units::toBase(2, 'kg', 'g'), '2026-10-02', 'Sour on arrival');

        $this->assertSame(SupplierPayment::CREDIT, $credit->kind);
        $this->assertSame(80_000, $credit->amount_paise);
        $this->assertSame(Units::toBase(8, 'kg', 'g'), app(StockService::class)->onHand($this->at['outlet'], $paneer));
        $this->assertSame(320_000, $this->purchasing->owed($this->at['outlet'], $this->dairy));
        $this->assertSame(1, StockMovement::allOutlets()->where('type', StockMovement::SUPPLIER_RETURN)->count());
    }

    public function test_a_delivery_with_no_supplier_books_stock_and_raises_nothing(): void
    {
        $onion = $this->stockItem($this->at['tenant'], 'Onion', 'g');

        $receipt = $this->purchasing->receive($this->at['outlet'], ['store' => $this->main, 'received_on' => '2026-10-01'], [$this->line($onion, 20, 'kg', 700)]);

        $this->assertNull($receipt->supplier_bill_id);
        $this->assertSame(0, SupplierBill::allOutlets()->count());
        $this->assertSame(Units::toBase(20, 'kg', 'g'), app(StockService::class)->onHand($this->at['outlet'], $onion));
    }

    public function test_one_business_cannot_see_another_business_suppliers(): void
    {
        $rival = $this->business('Rival Dhaba');
        Supplier::allTenants()->create(['tenant_id' => $rival['tenant'], 'name' => 'Secret Supplier']);

        $this->signInAs('owner', $this->at);

        $this->assertSame(['Amul Dairy'], Supplier::query()->pluck('name')->all());
    }
}
