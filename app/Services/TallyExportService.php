<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\Shop;
use App\Models\Supplier;
use App\Support\ReportFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One branch's books for a period, as a file Tally ERP 9 / TallyPrime imports.
 *
 * ---------------------------------------------------------------------------
 * What goes across, and as what
 * ---------------------------------------------------------------------------
 *
 *   invoice (not draft, not cancelled)   Sales        party Dr, sales/tax Cr
 *   cleared payment in                   Receipt      cash/bank Dr, party Cr
 *   cleared payment out                  Payment      party Dr, cash/bank Cr
 *   approved sales return                Credit Note  sales/tax Dr, party Cr
 *   posted goods receipt                 Purchase     purchase/tax Dr, party Cr
 *   approved purchase return             Debit Note   party Dr, purchase/tax Cr
 *   approved expense                     Payment      expense Dr, cash/bank Cr
 *
 * Every sale debits a party - the customer, or "Walk-in Customers" - and
 * every payment credits it. That is the double entry the app already keeps
 * (an invoice and the payments against it), and it is the only shape that
 * stays right for a split tender, a part payment and a credit sale alike:
 * debiting Cash on the sales voucher itself would be wrong for all three.
 *
 * ---------------------------------------------------------------------------
 * What is left out, deliberately
 * ---------------------------------------------------------------------------
 *
 *   - A payment that was reversed, and its reversal. The pair nets to
 *     nothing; exporting only the half that is still `cleared` would post
 *     money that never moved.
 *   - Pending and bounced cheques. They reach the books when they clear.
 *   - Returns settled as an exchange. The sale stands and the replacement
 *     left the shelf for the returned item; there is no money to post.
 *   - Stock. These are accounting vouchers (Tally's "Accounting Voucher
 *     View"); the app is the stock ledger, and keeping a second one in Tally
 *     is how the two come to disagree.
 *
 * ---------------------------------------------------------------------------
 * Every voucher balances, or it is not written
 * ---------------------------------------------------------------------------
 *
 * Tally rejects a voucher whose debits and credits differ by a paisa. The
 * tax and taxable figures are the ones stored on the document - the ones
 * that went on the GST return - and whatever is left between them and the
 * grand total goes to Round Off, which is exactly what that field already is.
 *
 * Each voucher carries a REMOTEID built from the branch and the document, so
 * Tally can recognise one it has already imported.
 */
class TallyExportService
{
    public const SALES = 'sales';

    public const RECEIPTS = 'receipts';

    public const PAYMENTS = 'payments';

    public const CREDIT_NOTES = 'credit_notes';

    public const PURCHASES = 'purchases';

    public const DEBIT_NOTES = 'debit_notes';

    public const EXPENSES = 'expenses';

    /** @var array<string, string> what the screen offers */
    public const TYPES = [
        self::SALES => 'Sales invoices',
        self::RECEIPTS => 'Money received',
        self::PAYMENTS => 'Money paid out (refunds, suppliers)',
        self::CREDIT_NOTES => 'Sales returns (credit notes)',
        self::PURCHASES => 'Purchases (goods receipts)',
        self::DEBIT_NOTES => 'Purchase returns (debit notes)',
        self::EXPENSES => 'Expenses',
    ];

    /** @var array<string, string> ledger name => Tally group, for the masters */
    private array $ledgers = [];

    /** @var array<string, string> ledger name => GSTIN, for party masters */
    private array $gstins = [];

    /** @var array<string, int> voucher type => how many, for the summary */
    private array $counts = [];

    /**
     * The whole import file.
     *
     * @param  array<int, string>  $types  keys of self::TYPES
     */
    public function export(Shop $shop, ReportFilters $range, array $types, bool $masters = true): string
    {
        $this->ledgers = [];
        $this->gstins = [];
        $this->counts = [];

        $from = $range->from->toDateString();
        $to = $range->to->toDateString();
        $types = array_values(array_intersect(array_keys(self::TYPES), $types));

        $vouchers = [];

        foreach ($types as $type) {
            foreach ($this->vouchersFor($type, $shop->id, $from, $to) as $voucher) {
                $vouchers[] = $voucher;
            }
        }

        $messages = [];

        // Masters first: Tally refuses a voucher that names a ledger it does
        // not have, and it reads the file top to bottom.
        if ($masters) {
            foreach ($this->ledgers as $name => $group) {
                $messages[] = $this->ledgerXml($name, $group, $this->gstins[$name] ?? null);
            }
        }

        foreach ($vouchers as $voucher) {
            $messages[] = $this->voucherXml($voucher);
        }

        return $this->envelope($messages);
    }

    /**
     * How many of each voucher the last export() wrote.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /* ------------------------------------------------------------ vouchers */

    /**
     * @return iterable<int, array<string, mixed>>
     */
    private function vouchersFor(string $type, int $shopId, string $from, string $to): iterable
    {
        return match ($type) {
            self::SALES => $this->sales($shopId, $from, $to),
            self::RECEIPTS => $this->payments($shopId, $from, $to, Payment::IN),
            self::PAYMENTS => $this->payments($shopId, $from, $to, Payment::OUT),
            self::CREDIT_NOTES => $this->creditNotes($shopId, $from, $to),
            self::PURCHASES => $this->purchases($shopId, $from, $to),
            self::DEBIT_NOTES => $this->debitNotes($shopId, $from, $to),
            self::EXPENSES => $this->expenses($shopId, $from, $to),
            default => [],
        };
    }

    /** @return iterable<int, array<string, mixed>> */
    private function sales(int $shopId, string $from, string $to): iterable
    {
        $invoices = Invoice::forShop($shopId)
            ->counted()
            ->between($from, $to)
            ->with(['items', 'customer' => fn ($q) => $q->withTrashed()])
            ->orderBy('invoiced_at')
            ->orderBy('id')
            ->lazy(200);

        foreach ($invoices as $invoice) {
            $party = $this->customerLedger($invoice->customer);
            $lines = [$party => (float) $invoice->grand_total];

            foreach ($this->taxableByRate($invoice->items) as $rate => $taxable) {
                $this->add($lines, $this->rated('sales', $rate), -$taxable);
            }

            $this->add($lines, $this->duty('output_cgst'), -(float) $invoice->cgst_total);
            $this->add($lines, $this->duty('output_sgst'), -(float) $invoice->sgst_total);
            $this->add($lines, $this->duty('output_igst'), -(float) $invoice->igst_total);
            $this->add($lines, $this->duty('output_cess'), -(float) $invoice->cess_total);

            yield $this->voucher('Sales', $invoice->invoiced_at, $invoice->number, $party, $lines,
                'sale-'.$invoice->id, $shopId,
                trim('Bill '.$invoice->number.($invoice->customer_name ? ' - '.$invoice->customer_name : '')));
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function payments(int $shopId, string $from, string $to, string $direction): iterable
    {
        $payments = Payment::forShop($shopId)
            ->where('direction', $direction)
            ->where('status', Payment::CLEARED)
            // A reversal and the payment it undid net to nothing; the
            // original is already excluded by being cancelled.
            ->whereNull('reverses_payment_id')
            ->between($from, $to)
            ->with('party')
            ->orderBy('paid_at')
            ->orderBy('id')
            ->lazy(200);

        foreach ($payments as $payment) {
            $party = $this->partyLedger($this->partyOf($payment));
            $money = $this->methodLedger($payment->method);
            $amount = (float) $payment->amount;

            $lines = $direction === Payment::IN
                ? [$money => $amount, $party => -$amount]
                : [$party => $amount, $money => -$amount];

            yield $this->voucher($direction === Payment::IN ? 'Receipt' : 'Payment',
                $payment->paid_at, $payment->number, $party, $lines,
                'pay-'.$payment->id, $shopId,
                trim(($payment->notes ?: ucfirst((string) $payment->method)).' '.($payment->transaction_ref ?? '')));
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function creditNotes(int $shopId, string $from, string $to): iterable
    {
        $returns = SalesReturn::forShop($shopId)
            ->counted()
            ->where('settlement', '!=', SalesReturn::NONE)
            ->between($from, $to)
            ->with(['items', 'invoice', 'customer' => fn ($q) => $q->withTrashed()])
            ->orderBy('returned_on')
            ->orderBy('id')
            ->lazy(200);

        foreach ($returns as $return) {
            $party = $this->customerLedger($return->customer);
            $lines = [$party => -(float) $return->grand_total];

            foreach ($this->taxableByRate($return->items) as $rate => $taxable) {
                $this->add($lines, $this->rated('sales', $rate), $taxable);
            }

            foreach ($this->splitTax((float) $return->items->sum('tax_amount'), (bool) $return->invoice?->is_inter_state, 'output') as $ledger => $amount) {
                $this->add($lines, $ledger, $amount);
            }

            yield $this->voucher('Credit Note', $return->returned_on, $return->reference, $party, $lines,
                'cn-'.$return->id, $shopId,
                trim('Return against '.($return->invoice?->number ?? 'bill').($return->reason ? ' - '.$return->reason : '')));
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function purchases(int $shopId, string $from, string $to): iterable
    {
        $receipts = GoodsReceipt::forShop($shopId)
            ->counted()
            ->between($from, $to)
            ->with(['items', 'supplier' => fn ($q) => $q->withTrashed()])
            ->orderBy('received_on')
            ->orderBy('id')
            ->lazy(200);

        foreach ($receipts as $receipt) {
            $party = $this->supplierLedger($receipt->supplier);
            $lines = [$party => -(float) $receipt->grand_total];

            foreach ($this->taxableByRate($receipt->items) as $rate => $taxable) {
                $this->add($lines, $this->rated('purchase', $rate), $taxable);
            }

            $this->add($lines, $this->duty('input_cgst'), (float) $receipt->cgst_total);
            $this->add($lines, $this->duty('input_sgst'), (float) $receipt->sgst_total);
            $this->add($lines, $this->duty('input_igst'), (float) $receipt->igst_total);
            $this->add($lines, $this->expenseLedger(config('tally.ledgers.freight'), 'direct_expense'), (float) $receipt->other_charges);

            yield $this->voucher('Purchase', $receipt->received_on, $receipt->bill_number ?: $receipt->reference, $party, $lines,
                'pur-'.$receipt->id, $shopId,
                trim('GRN '.$receipt->reference.($receipt->bill_number ? ', supplier bill '.$receipt->bill_number : '')));
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function debitNotes(int $shopId, string $from, string $to): iterable
    {
        $returns = PurchaseReturn::forShop($shopId)
            ->counted()
            ->where('settlement', '!=', PurchaseReturn::NONE)
            ->between($from, $to)
            ->with(['items', 'goodsReceipt', 'supplier' => fn ($q) => $q->withTrashed()])
            ->orderBy('returned_on')
            ->orderBy('id')
            ->lazy(200);

        foreach ($returns as $return) {
            $party = $this->supplierLedger($return->supplier);
            $lines = [$party => (float) $return->grand_total];

            foreach ($this->taxableByRate($return->items) as $rate => $taxable) {
                $this->add($lines, $this->rated('purchase', $rate), -$taxable);
            }

            foreach ($this->splitTax((float) $return->items->sum('tax_amount'), (bool) $return->goodsReceipt?->is_inter_state, 'input') as $ledger => $amount) {
                $this->add($lines, $ledger, -$amount);
            }

            yield $this->voucher('Debit Note', $return->returned_on, $return->reference, $party, $lines,
                'dn-'.$return->id, $shopId,
                trim('Returned to supplier'.($return->reason ? ' - '.$return->reason : '')));
        }
    }

    /** @return iterable<int, array<string, mixed>> */
    private function expenses(int $shopId, string $from, string $to): iterable
    {
        $expenses = Expense::forShop($shopId)
            ->counted()
            ->between($from, $to)
            ->with('category')
            ->orderBy('spent_on')
            ->orderBy('id')
            ->lazy(200);

        foreach ($expenses as $expense) {
            $head = $this->expenseLedger($expense->category?->name ?: config('tally.ledgers.expense_fallback'), 'indirect_expense');
            $money = $this->methodLedger($expense->method);
            $amount = (float) $expense->amount;

            yield $this->voucher('Payment', $expense->spent_on, $expense->reference, $head,
                [$head => $amount, $money => -$amount],
                'exp-'.$expense->id, $shopId,
                trim($expense->title.($expense->paid_to ? ' - paid to '.$expense->paid_to : '')));
        }
    }

    /* ------------------------------------------------------------- helpers */

    /**
     * Assemble one voucher, balancing it through Round Off.
     *
     * @param  array<string, float>  $lines  ledger => amount, debit positive
     * @return array<string, mixed>
     */
    private function voucher(string $type, mixed $date, ?string $number, string $party, array $lines, string $key, int $shopId, string $narration): array
    {
        $difference = round(array_sum($lines), 2);

        if (abs($difference) >= 0.005) {
            $this->add($lines, $this->expenseLedger(config('tally.ledgers.round_off'), 'indirect_expense'), -$difference);
        }

        $this->counts[$type] = ($this->counts[$type] ?? 0) + 1;

        return [
            'type' => $type,
            'date' => Carbon::parse($date),
            'number' => (string) $number,
            'party' => $party,
            'narration' => $narration,
            'remote_id' => sprintf('pos-%d-%s', $shopId, $key),
            'lines' => array_filter($lines, fn (float $amount) => abs($amount) >= 0.005),
        ];
    }

    /**
     * Taxable value per GST rate, from a document's own lines.
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $items
     * @return array<string, float> rate => taxable
     */
    private function taxableByRate(Collection $items): array
    {
        $byRate = [];

        foreach ($items as $item) {
            $rate = $this->rate((float) $item->tax_rate);
            $byRate[$rate] = round(($byRate[$rate] ?? 0) + (float) $item->taxable_value, 2);
        }

        return $byRate;
    }

    /**
     * A return carries one tax figure per line; Tally wants it by head.
     *
     * Split the way the original document was taxed, which is the only way a
     * return can be: an intra-state sale cannot be reversed as IGST.
     *
     * @return array<string, float>
     */
    private function splitTax(float $tax, bool $interState, string $side): array
    {
        if (abs($tax) < 0.005) {
            return [];
        }

        if ($interState) {
            return [$this->duty($side.'_igst') => round($tax, 2)];
        }

        $central = round($tax / 2, 2);

        return [
            $this->duty($side.'_cgst') => $central,
            $this->duty($side.'_sgst') => round($tax - $central, 2),
        ];
    }

    /** @param  array<string, float>  $lines */
    private function add(array &$lines, string $ledger, float $amount): void
    {
        if (abs($amount) < 0.005) {
            return;
        }

        $lines[$ledger] = round(($lines[$ledger] ?? 0) + $amount, 2);
    }

    /** "5", "12.5", "0" - the way a rate is written in a ledger name. */
    private function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function rated(string $kind, string $rate): string
    {
        $name = sprintf((string) config("tally.ledgers.{$kind}"), $rate);

        return $this->remember($name, $kind === 'sales' ? 'sales' : 'purchase');
    }

    private function duty(string $key): string
    {
        return $this->remember((string) config("tally.ledgers.{$key}"), 'duty');
    }

    private function expenseLedger(string $name, string $group): string
    {
        return $this->remember($name, $group);
    }

    private function methodLedger(?string $method): string
    {
        $name = (string) (config("tally.ledgers.methods.{$method}") ?? config('tally.ledgers.methods.cash'));

        return $this->remember($name, $name === config('tally.ledgers.methods.cash') ? 'cash' : 'bank');
    }

    /**
     * Who a payment was with, even if they have since been deleted.
     *
     * The morph relation skips soft-deleted rows, and a payment from a
     * customer deleted last week would then post to Walk-in Customers - a
     * different ledger from the one their invoice debited.
     */
    private function partyOf(Payment $payment): Customer|Supplier|null
    {
        if ($payment->party instanceof Customer || $payment->party instanceof Supplier) {
            return $payment->party;
        }

        return match ($payment->party_type) {
            Customer::class => Customer::withTrashed()->find($payment->party_id),
            Supplier::class => Supplier::withTrashed()->find($payment->party_id),
            default => null,
        };
    }

    private function partyLedger(mixed $party): string
    {
        return match (true) {
            $party instanceof Supplier => $this->supplierLedger($party),
            $party instanceof Customer => $this->customerLedger($party),
            default => $this->customerLedger(null),
        };
    }

    /**
     * A customer's ledger, or the walk-in one.
     *
     * The code goes in the name because Tally ledger names are unique and
     * customer names are not - two "Rahul Sharma"s are an ordinary Tuesday.
     */
    private function customerLedger(?Customer $customer): string
    {
        if ($customer === null) {
            return $this->remember((string) config('tally.ledgers.walk_in'), 'debtor');
        }

        $name = $this->partyName($customer->name, $customer->code, $customer->id);

        if ($customer->gstin) {
            $this->gstins[$name] = $customer->gstin;
        }

        return $this->remember($name, 'debtor');
    }

    private function supplierLedger(?Supplier $supplier): string
    {
        if ($supplier === null) {
            return $this->remember('Unknown Supplier', 'creditor');
        }

        $name = $this->partyName($supplier->name, $supplier->code, $supplier->id);

        if ($supplier->gstin) {
            $this->gstins[$name] = $supplier->gstin;
        }

        return $this->remember($name, 'creditor');
    }

    private function partyName(?string $name, ?string $code, int $id): string
    {
        $name = trim((string) $name) ?: 'Party';

        return $name.' ('.($code ?: '#'.$id).')';
    }

    private function remember(string $name, string $groupKey): string
    {
        $this->ledgers[$name] ??= (string) config("tally.groups.{$groupKey}");

        return $name;
    }

    /* ----------------------------------------------------------------- xml */

    /** @param  array<int, string>  $messages */
    private function envelope(array $messages): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            ."<ENVELOPE>\n"
            ." <HEADER>\n  <TALLYREQUEST>Import Data</TALLYREQUEST>\n </HEADER>\n"
            ." <BODY>\n  <IMPORTDATA>\n"
            ."   <REQUESTDESC>\n    <REPORTNAME>Vouchers</REPORTNAME>\n   </REQUESTDESC>\n"
            ."   <REQUESTDATA>\n"
            .implode('', $messages)
            ."   </REQUESTDATA>\n"
            ."  </IMPORTDATA>\n </BODY>\n"
            ."</ENVELOPE>\n";
    }

    private function ledgerXml(string $name, string $group, ?string $gstin): string
    {
        $isDuty = $group === config('tally.groups.duty');
        $head = $isDuty ? $this->dutyHead($name) : null;

        return '    <TALLYMESSAGE xmlns:UDF="TallyUDF">'."\n"
            .'     <LEDGER NAME="'.$this->e($name).'" ACTION="Create">'."\n"
            .'      <NAME.LIST><NAME>'.$this->e($name).'</NAME></NAME.LIST>'."\n"
            .'      <PARENT>'.$this->e($group).'</PARENT>'."\n"
            .'      <ISBILLWISEON>No</ISBILLWISEON>'."\n"
            .($isDuty ? "      <TAXTYPE>GST</TAXTYPE>\n" : '')
            .($head ? '      <GSTDUTYHEAD>'.$head."</GSTDUTYHEAD>\n" : '')
            .($gstin ? '      <PARTYGSTIN>'.$this->e($gstin)."</PARTYGSTIN>\n" : '')
            ."     </LEDGER>\n"
            ."    </TALLYMESSAGE>\n";
    }

    /** @param  array<string, mixed>  $voucher */
    private function voucherXml(array $voucher): string
    {
        $type = $this->e($voucher['type']);

        $xml = '    <TALLYMESSAGE xmlns:UDF="TallyUDF">'."\n"
            .'     <VOUCHER REMOTEID="'.$this->e($voucher['remote_id']).'" VCHTYPE="'.$type.'" ACTION="Create" OBJVIEW="Accounting Voucher View">'."\n"
            .'      <DATE>'.$voucher['date']->format('Ymd').'</DATE>'."\n"
            .'      <VOUCHERTYPENAME>'.$type.'</VOUCHERTYPENAME>'."\n"
            .'      <VOUCHERNUMBER>'.$this->e($voucher['number']).'</VOUCHERNUMBER>'."\n"
            .'      <REFERENCE>'.$this->e($voucher['number']).'</REFERENCE>'."\n"
            .'      <PARTYLEDGERNAME>'.$this->e($voucher['party']).'</PARTYLEDGERNAME>'."\n"
            .'      <NARRATION>'.$this->e($voucher['narration']).'</NARRATION>'."\n"
            ."      <PERSISTEDVIEW>Accounting Voucher View</PERSISTEDVIEW>\n";

        foreach ($voucher['lines'] as $ledger => $amount) {
            $debit = $amount > 0;

            /*
             | Tally's sign convention, which is the reverse of what anyone
             | would guess: a debit is written as a negative AMOUNT with
             | ISDEEMEDPOSITIVE=Yes, a credit as a positive one with No.
             */
            $xml .= "      <ALLLEDGERENTRIES.LIST>\n"
                .'       <LEDGERNAME>'.$this->e((string) $ledger).'</LEDGERNAME>'."\n"
                .'       <ISDEEMEDPOSITIVE>'.($debit ? 'Yes' : 'No').'</ISDEEMEDPOSITIVE>'."\n"
                .'       <AMOUNT>'.number_format($debit ? -$amount : abs($amount), 2, '.', '').'</AMOUNT>'."\n"
                ."      </ALLLEDGERENTRIES.LIST>\n";
        }

        return $xml."     </VOUCHER>\n    </TALLYMESSAGE>\n";
    }

    private function dutyHead(string $ledger): ?string
    {
        $ledgers = config('tally.ledgers');

        return match ($ledger) {
            $ledgers['output_cgst'], $ledgers['input_cgst'] => 'CGST',
            $ledgers['output_sgst'], $ledgers['input_sgst'] => 'SGST/UTGST',
            $ledgers['output_igst'], $ledgers['input_igst'] => 'IGST',
            $ledgers['output_cess'] => 'Cess',
            default => null,
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
