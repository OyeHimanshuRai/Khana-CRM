<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\StockService;
use App\Services\TallyExportService;
use App\Support\CurrentShop;
use App\Support\ReportFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * The Tally file (§ accounting export).
 *
 * What matters is what an accountant would check first: every voucher
 * balances to the paisa, tax lands on the right head, the party a sale
 * debits is the party its payment credits, and nothing that was undone in
 * the app turns up in the books.
 */
class TallyExportTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        $this->shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $this->shop->forceFill(['state' => 'Maharashtra', 'state_code' => '27'])->save();
        $this->warehouse = Warehouse::allShops()->where('shop_id', $this->shop->id)->firstOrFail();
    }

    /* ---------------------------------------------------------- fixtures */

    /** A dish at ₹236 including 18% GST: ₹200 taxable and ₹36 tax. */
    private function product(): Product
    {
        static $n = 0;
        $n++;

        $product = Product::create([
            'name' => 'Paneer Tikka '.$n,
            'slug' => Product::uniqueSlug('Paneer Tikka '.$n),
            'sku' => Product::generateSku('PT'.$n),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100,
            'mrp' => 300,
            'selling_price' => 236,
            'tax_inclusive' => true,
        ]);

        app(StockService::class)->receive(
            $product, 50, $this->warehouse, null, 100,
            StockMovement::PURCHASE, null, null, $this->shop->id
        );

        return $product;
    }

    private function customer(array $attributes = []): Customer
    {
        $customer = new Customer([
            'shop_id' => $this->shop->id,
            'code' => 'C-'.uniqid(),
            'name' => 'Asha Verma',
            'mobile' => '98'.random_int(10000000, 99999999),
            'type' => 'regular',
            'state' => 'Maharashtra',
            ...$attributes,
        ]);

        $customer->saveQuietly();

        return $customer;
    }

    /** @param array<string, mixed> $extra */
    private function sell(array $extra = []): Invoice
    {
        return app(InvoiceService::class)->create([
            'shop' => $this->shop,
            'warehouse' => $this->warehouse,
            'items' => [['product_id' => $this->product()->id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => 236]],
            ...$extra,
        ]);
    }

    private function secondBranch(): Shop
    {
        return Shop::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => 'Second Branch',
            'code' => 'BR2',
            'slug' => 'second-branch',
        ]);
    }

    /** @param array<int, string> $types */
    private function export(array $types = [TallyExportService::SALES, TallyExportService::RECEIPTS], bool $masters = true): SimpleXMLElement
    {
        $range = ReportFilters::fromRequest(Request::create('/', 'GET', ['preset' => 'today']));

        $xml = app(TallyExportService::class)->export($this->shop->fresh(), $range, $types, $masters);

        return new SimpleXMLElement($xml);
    }

    /** @return array<int, SimpleXMLElement> */
    private function vouchers(SimpleXMLElement $xml, ?string $type = null): array
    {
        $all = $xml->xpath('//VOUCHER') ?: [];

        return array_values(array_filter($all, fn ($v) => $type === null || (string) $v['VCHTYPE'] === $type));
    }

    /** ledger => signed amount, debit positive, as an accountant reads it. */
    private function entries(SimpleXMLElement $voucher): array
    {
        $entries = [];

        foreach ($voucher->xpath('ALLLEDGERENTRIES.LIST') as $line) {
            $amount = (float) $line->AMOUNT;
            // Tally writes debits negative; flip to the way a ledger reads.
            $entries[(string) $line->LEDGERNAME] = round(-$amount, 2);
        }

        return $entries;
    }

    /* ------------------------------------------------------------- tests */

    public function test_a_cash_sale_becomes_a_sales_voucher_and_a_receipt(): void
    {
        $invoice = $this->sell();

        $xml = $this->export();

        [$sale] = $this->vouchers($xml, 'Sales');
        $this->assertSame($invoice->number, (string) $sale->VOUCHERNUMBER);
        $this->assertSame(today()->format('Ymd'), (string) $sale->DATE);
        $this->assertSame([
            'Walk-in Customers' => 236.0,
            'Sales @ 18%' => -200.0,
            'Output CGST' => -18.0,
            'Output SGST' => -18.0,
        ], $this->entries($sale));

        [$receipt] = $this->vouchers($xml, 'Receipt');
        $this->assertSame(['Cash' => 236.0, 'Walk-in Customers' => -236.0], $this->entries($receipt));
    }

    public function test_every_voucher_balances_to_the_paisa(): void
    {
        $this->sell();
        $this->sell(['customer' => $this->customer(['state' => 'Gujarat'])]);
        $this->sell(['payments' => [['method' => 'upi', 'amount' => 100], ['method' => 'cash', 'amount' => 136]]]);

        foreach ($this->vouchers($this->export()) as $voucher) {
            $this->assertEqualsWithDelta(0.0, array_sum($this->entries($voucher)), 0.001,
                (string) $voucher->VOUCHERNUMBER.' does not balance');
        }
    }

    public function test_an_inter_state_customer_is_charged_igst_on_their_own_ledger(): void
    {
        $customer = $this->customer(['state' => 'Gujarat', 'gstin' => '24ABCDE1234F1Z5']);

        $this->sell(['customer' => $customer]);

        $xml = $this->export();
        [$sale] = $this->vouchers($xml, 'Sales');
        $ledger = 'Asha Verma ('.$customer->code.')';

        $this->assertSame([
            $ledger => 236.0,
            'Sales @ 18%' => -200.0,
            'Output IGST' => -36.0,
        ], $this->entries($sale));

        // The party's master carries their GSTIN, and the tax ledger its head.
        $master = $xml->xpath('//LEDGER[@NAME="'.$ledger.'"]')[0];
        $this->assertSame('Sundry Debtors', (string) $master->PARENT);
        $this->assertSame('24ABCDE1234F1Z5', (string) $master->PARTYGSTIN);

        $igst = $xml->xpath('//LEDGER[@NAME="Output IGST"]')[0];
        $this->assertSame('Duties & Taxes', (string) $igst->PARENT);
        $this->assertSame('IGST', (string) $igst->GSTDUTYHEAD);

        // The receipt credits the same ledger the sale debited.
        [$receipt] = $this->vouchers($xml, 'Receipt');
        $this->assertSame(-236.0, $this->entries($receipt)[$ledger]);
    }

    public function test_a_split_tender_posts_each_method_to_its_own_ledger(): void
    {
        $this->sell(['payments' => [['method' => 'upi', 'amount' => 100], ['method' => 'cash', 'amount' => 136]]]);

        $receipts = $this->vouchers($this->export(), 'Receipt');

        $this->assertCount(2, $receipts);
        $debits = array_merge(...array_map(fn ($r) => array_filter($this->entries($r), fn ($a) => $a > 0), $receipts));
        $this->assertEqualsCanonicalizing(['UPI Collections' => 100.0, 'Cash' => 136.0], $debits);
    }

    public function test_a_cancelled_bill_and_its_payments_stay_out(): void
    {
        $kept = $this->sell();
        $cancelled = $this->sell();

        app(InvoiceService::class)->cancel($cancelled, 'Wrong table');

        $xml = $this->export();

        $numbers = array_map(fn ($v) => (string) $v->VOUCHERNUMBER, $this->vouchers($xml, 'Sales'));
        $this->assertSame([$kept->number], $numbers);
        $this->assertCount(1, $this->vouchers($xml, 'Receipt'));
    }

    public function test_a_reversed_payment_and_its_reversal_both_stay_out(): void
    {
        $invoice = $this->sell();
        $payment = Payment::forShop($this->shop->id)->where('reference_id', $invoice->id)->firstOrFail();

        app(PaymentService::class)->reverse($payment, 'Card machine double-charged');

        $xml = $this->export([TallyExportService::RECEIPTS, TallyExportService::PAYMENTS]);

        // Exporting the reversal alone would post ₹236 leaving a till it
        // never came into.
        $this->assertSame([], $this->vouchers($xml));
    }

    public function test_masters_can_be_left_out(): void
    {
        $this->sell();

        $xml = $this->export(masters: false);

        $this->assertSame([], $xml->xpath('//LEDGER') ?: []);
        $this->assertNotEmpty($this->vouchers($xml));
    }

    public function test_only_the_kinds_asked_for_are_written(): void
    {
        $this->sell();

        $xml = $this->export([TallyExportService::SALES]);

        $this->assertCount(1, $this->vouchers($xml, 'Sales'));
        $this->assertSame([], $this->vouchers($xml, 'Receipt'));
    }

    public function test_another_branch_s_sales_never_appear(): void
    {
        $this->sell();

        $other = $this->secondBranch();

        $range = ReportFilters::fromRequest(Request::create('/', 'GET', ['preset' => 'today']));
        $xml = new SimpleXMLElement(app(TallyExportService::class)->export($other, $range, array_keys(TallyExportService::TYPES)));

        $this->assertSame([], $this->vouchers($xml));
    }

    public function test_a_supplier_bill_debits_purchase_input_tax_and_freight(): void
    {
        $supplier = Supplier::create([
            'shop_id' => $this->shop->id,
            'code' => 'S-01',
            'name' => 'Fresh Dairy',
            'gstin' => '27AAAAA0000A1Z5',
        ]);

        $receipt = new GoodsReceipt();
        $receipt->forceFill([
            'shop_id' => $this->shop->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'reference' => 'GRN-0001',
            'received_on' => today(),
            'status' => GoodsReceipt::POSTED,
            'bill_number' => 'FD/778',
            'subtotal' => 1000, 'discount_total' => 0,
            'cgst_total' => 25, 'sgst_total' => 25, 'igst_total' => 0, 'tax_total' => 50,
            'other_charges' => 40, 'round_off' => 0, 'grand_total' => 1090,
            'paid_total' => 0, 'due_total' => 1090,
        ])->saveQuietly();

        $item = new GoodsReceiptItem();
        $item->forceFill([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product()->id,
            'product_name' => 'Paneer 1kg',
            'quantity' => 10, 'unit_cost' => 100,
            'taxable_value' => 1000, 'tax_rate' => 5,
            'cgst_amount' => 25, 'sgst_amount' => 25, 'igst_amount' => 0,
            'line_total' => 1050,
        ])->saveQuietly();

        [$voucher] = $this->vouchers($this->export([TallyExportService::PURCHASES]), 'Purchase');

        $this->assertSame('FD/778', (string) $voucher->VOUCHERNUMBER);
        $this->assertSame([
            'Fresh Dairy (S-01)' => -1090.0,
            'Purchase @ 5%' => 1000.0,
            'Input CGST' => 25.0,
            'Input SGST' => 25.0,
            'Freight & Other Charges' => 40.0,
        ], $this->entries($voucher));
    }

    public function test_an_approved_expense_posts_to_its_category(): void
    {
        // The seeded heads are shared by every branch.
        $category = ExpenseCategory::query()->orderBy('id')->firstOrFail();

        $expense = new Expense();
        $expense->forceFill([
            'shop_id' => $this->shop->id,
            'expense_category_id' => $category->id,
            'reference' => 'EXP-0001',
            'spent_on' => today(),
            'title' => 'Gas cylinder',
            'amount' => 1500,
            'method' => 'cash',
            'status' => Expense::APPROVED,
        ])->saveQuietly();

        $draft = $expense->replicate()->forceFill(['reference' => 'EXP-0002', 'status' => Expense::DRAFT]);
        $draft->saveQuietly();

        $vouchers = $this->vouchers($this->export([TallyExportService::EXPENSES]), 'Payment');

        // The one awaiting approval has not been spent as far as the books know.
        $this->assertCount(1, $vouchers);
        $this->assertSame([$category->name => 1500.0, 'Cash' => -1500.0], $this->entries($vouchers[0]));
    }

    public function test_a_sales_return_is_a_credit_note_and_an_exchange_is_not(): void
    {
        $invoice = $this->sell();

        $productId = $invoice->items()->value('product_id');

        $make = function (string $reference, string $settlement) use ($invoice, $productId) {
            $return = new SalesReturn();
            $return->forceFill([
                'shop_id' => $this->shop->id,
                'warehouse_id' => $this->warehouse->id,
                'invoice_id' => $invoice->id,
                'reference' => $reference,
                'returned_on' => today(),
                'status' => SalesReturn::APPROVED,
                'subtotal' => 200, 'tax_total' => 36, 'grand_total' => 236,
                'settlement' => $settlement,
                'refund_amount' => 0,
            ])->saveQuietly();

            $line = new SalesReturnItem();
            $line->forceFill([
                'sales_return_id' => $return->id,
                'product_id' => $productId,
                'product_name' => 'Paneer Tikka',
                'quantity' => 1, 'unit_price' => 236,
                'taxable_value' => 200, 'tax_rate' => 18, 'tax_amount' => 36,
                'line_total' => 236,
            ])->saveQuietly();
        };

        $make('SR-0001', SalesReturn::CREDIT);
        $make('SR-0002', SalesReturn::NONE);

        $notes = $this->vouchers($this->export([TallyExportService::CREDIT_NOTES]), 'Credit Note');

        $this->assertCount(1, $notes);
        $this->assertSame('SR-0001', (string) $notes[0]->VOUCHERNUMBER);
        $this->assertSame([
            'Walk-in Customers' => -236.0,
            'Sales @ 18%' => 200.0,
            'Output CGST' => 18.0,
            'Output SGST' => 18.0,
        ], $this->entries($notes[0]));
    }

    /* -------------------------------------------------------------- http */

    private function accountant(array $permissions): User
    {
        $user = User::create([
            'tenant_id' => $this->shop->tenant_id,
            'name' => 'The Accountant',
            'email' => 'ca'.uniqid().'@example.test',
            'password' => 'accountant-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $this->shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    public function test_the_screen_and_the_download(): void
    {
        $this->sell();
        CurrentShop::forget();

        $user = $this->accountant(['reports.tally_export.view', 'reports.tally_export.download']);

        $this->actingAs($user)->get(route('admin.reports.tally'))
            ->assertOk()
            ->assertSee('Export to Tally')
            ->assertSee('Download Tally file');

        $response = $this->actingAs($user)->get(route('admin.reports.tally.download', [
            'preset' => 'today',
            'types' => ['sales', 'receipts'],
            'masters' => 1,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('attachment; filename="tally-', $response->headers->get('Content-Disposition'));
        $this->assertSame('2', $response->headers->get('X-Tally-Vouchers'));
        $this->assertStringContainsString('<VOUCHER ', $response->getContent());

        $this->assertDatabaseHas('activity_logs', ['event' => 'report.tally_exported']);
    }

    public function test_downloading_needs_its_own_right(): void
    {
        $viewer = $this->accountant(['reports.tally_export.view']);

        $this->actingAs($viewer)->get(route('admin.reports.tally'))
            ->assertOk()
            ->assertDontSee('Download Tally file');

        $this->actingAs($viewer)
            ->get(route('admin.reports.tally.download', ['types' => ['sales']]))
            ->assertForbidden();
    }

    public function test_the_consolidated_view_is_asked_to_pick_a_branch(): void
    {
        $user = $this->accountant(['reports.tally_export.view', 'reports.tally_export.download']);
        $user->forceFill(['current_shop_id' => null, 'all_shops_view' => true])->save();

        // Two branches, so "All shops" is a real view rather than the only one.
        $second = $this->secondBranch();
        $user->shops()->syncWithoutDetaching([$second->id]);
        CurrentShop::forget();

        $this->actingAs($user->fresh())
            ->get(route('admin.reports.tally.download', ['types' => ['sales']]))
            ->assertRedirect(route('admin.reports.tally'));
    }
}
