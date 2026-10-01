<?php

namespace App\Services;

use App\Models\OutletSetting;
use App\Models\SalesImport;
use App\Models\SalesItem;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Khana\Kit\Support\Decimal;
use RuntimeException;

/**
 * Item-wise sales from any billing software, by CSV.
 *
 * An outlet with no CRM still has to tell ERP what it sold. Every billing
 * package can export "date, item, quantity, amount", each in its own
 * layout, so the import is three small steps:
 *
 *   1. Say which column is which. Asked once per outlet and remembered.
 *   2. Match their item names to ERP's sales items. Asked once per name and
 *      remembered; a name nobody has matched becomes a new sales item, which
 *      then waits under "Needs recipe" like any dish.
 *   3. Import. Rows are grouped into one sale per bill - or one per day,
 *      when the file has no bill numbers - and each sale goes through the
 *      same door CRM's do, so stock is consumed by the same rules.
 *
 * A sale is keyed by its date and bill number. Importing the same file
 * again records nothing twice.
 */
class SalesImporter
{
    /** The columns an import can use; the first three are needed. */
    public const FIELDS = [
        'date' => 'Date',
        'item' => 'Item name',
        'qty' => 'Quantity',
        'amount' => 'Amount (before tax)',
        'tax' => 'Tax',
        'size' => 'Size / variant',
        'invoice' => 'Bill number',
    ];

    public const REQUIRED = ['date', 'item', 'qty'];

    public function __construct(
        private readonly Consumption $consumption,
        private readonly MenuCopy $menu,
    ) {}

    /* ------------------------------------------------------------- read */

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('That file could not be opened.');
        }

        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            // A blank line comes back as one null cell.
            if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
                continue;
            }

            $rows[] = array_map(fn ($cell) => trim((string) $cell), $row);

            if (count($rows) > 20_001) {
                fclose($handle);

                throw new RuntimeException('That file has more than 20,000 rows. Import a shorter period at a time.');
            }
        }

        fclose($handle);

        if (count($rows) < 2) {
            throw new RuntimeException('That file has no sales in it - it needs a heading row and at least one row under it.');
        }

        // Excel puts a byte-order mark in front of the first heading.
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);

        return ['headers' => array_shift($rows), 'rows' => $rows];
    }

    /**
     * Which column is which: what the outlet said last time, if the file
     * still has those headings, or a guess from the headings themselves.
     *
     * @param  list<string>  $headers
     * @return array<string, int|null> field => column index
     */
    public function layoutFor(string $outletId, array $headers): array
    {
        $remembered = Outlets::settings($outletId)->csv_layout ?? [];
        $lower = array_map(fn (string $h) => Str::lower($h), $headers);
        $layout = [];

        $hints = [
            'date' => ['date', 'bill date', 'invoice date', 'day'],
            'item' => ['item', 'item name', 'product', 'dish', 'menu item', 'name'],
            'qty' => ['qty', 'quantity', 'sold', 'units'],
            'amount' => ['amount', 'net', 'net amount', 'total', 'sales', 'value'],
            'tax' => ['tax', 'gst', 'tax amount'],
            'size' => ['size', 'variant', 'portion'],
            'invoice' => ['bill', 'bill no', 'invoice', 'invoice no', 'bill number', 'invoice number'],
        ];

        foreach (array_keys(self::FIELDS) as $field) {
            $heading = $remembered[$field] ?? null;
            $index = $heading === null ? false : array_search(Str::lower($heading), $lower, true);

            if ($index === false) {
                $index = false;

                foreach ($hints[$field] as $hint) {
                    if (($found = array_search($hint, $lower, true)) !== false) {
                        $index = $found;
                        break;
                    }
                }
            }

            $layout[$field] = $index === false ? null : (int) $index;
        }

        return $layout;
    }

    /**
     * The distinct item names in a file, each with what ERP thinks it is.
     *
     * @param  list<list<string>>  $rows
     * @param  array<string, int|null>  $layout
     * @return list<array{alias: string, item: string, size: ?string, rows: int, sales_item_id: ?int, sales_item_size_id: int}>
     */
    public function names(string $outletId, array $rows, array $layout): array
    {
        $names = [];

        foreach ($rows as $row) {
            $item = $row[$layout['item']] ?? '';

            if ($item === '') {
                continue;
            }

            $size = $layout['size'] === null ? null : (($row[$layout['size']] ?? '') ?: null);
            $alias = self::alias($item, $size);

            $names[$alias] ??= ['alias' => $alias, 'item' => $item, 'size' => $size, 'rows' => 0, 'sales_item_id' => null, 'sales_item_size_id' => 0];
            $names[$alias]['rows']++;
        }

        $known = DB::table('sales_item_aliases')->where('outlet_id', $outletId)->whereIn('alias', array_keys($names))->get()->keyBy('alias');

        $byName = SalesItem::allOutlets()->where('outlet_id', $outletId)->get(['id', 'name'])
            ->keyBy(fn (SalesItem $item) => Str::lower($item->name));

        foreach ($names as $alias => &$name) {
            if (isset($known[$alias])) {
                $name['sales_item_id'] = (int) $known[$alias]->sales_item_id;
                $name['sales_item_size_id'] = (int) $known[$alias]->sales_item_size_id;
            } elseif ($name['size'] === null && isset($byName[Str::lower($name['item'])])) {
                $name['sales_item_id'] = $byName[Str::lower($name['item'])]->id;
            }
        }

        unset($name);

        return array_values($names);
    }

    /* ----------------------------------------------------------- import */

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int|null>  $layout
     * @param  list<string>  $headers
     * @param  array<string, array{sales_item_id: ?int, sales_item_size_id?: int}>  $matches  alias => what the person chose; a missing or null id means "make a new sales item"
     */
    public function import(string $outletId, string $fileName, array $headers, array $rows, array $layout, array $matches = []): SalesImport
    {
        foreach (self::REQUIRED as $field) {
            if (($layout[$field] ?? null) === null) {
                throw new RuntimeException('Say which column holds the '.Str::lower(self::FIELDS[$field]).'.');
            }
        }

        $tenant = Outlets::tenantOf($outletId);

        return DB::transaction(function () use ($outletId, $tenant, $fileName, $headers, $rows, $layout, $matches) {
            $this->rememberLayout($outletId, $tenant, $headers, $layout);

            $import = SalesImport::allOutlets()->create([
                'outlet_id' => $outletId,
                'file_name' => $fileName,
                'rows' => count($rows),
                'user_id' => Auth::id(),
            ]);

            $targets = $this->targets($outletId, $tenant, $rows, $layout, $matches);
            $made = 0;
            $skipped = 0;

            foreach ($this->bills($rows, $layout) as $ref => $bill) {
                $lines = [];
                $net = 0;
                $tax = 0;

                foreach ($bill['rows'] as $row) {
                    $alias = self::alias($row['item'], $row['size']);

                    $lines[] = [
                        'ref' => null,
                        'sales_item_id' => $targets[$alias]['sales_item_id'],
                        'sales_item_size_id' => $targets[$alias]['sales_item_size_id'],
                        'item_ref' => $alias,
                        'variant_ref' => null,
                        'option_refs' => [],
                        'name' => trim($row['item'].($row['size'] ? ' ('.$row['size'].')' : '')),
                        'qty' => Decimal::format($row['qty'], 4),
                        'net_paise' => $row['net'],
                        'tax_paise' => $row['tax'],
                        'kitchen_refs' => [],
                    ];

                    $net += $row['net'];
                    $tax += $row['tax'];
                }

                [, $new] = $this->consumption->saleRecorded($tenant, $outletId, SalesItem::CSV, [
                    'ref' => $ref,
                    'invoice_no' => Str::limit($bill['invoice'] ?? 'Day '.$bill['date'], 50, ''),
                    'business_date' => $bill['date'],
                    'channel' => 'other',
                    'totals' => ['net_paise' => $net, 'tax_paise' => $tax, 'round_off_paise' => 0, 'grand_paise' => $net + $tax],
                    'lines' => $lines,
                ], importId: $import->id);

                $new ? $made++ : $skipped++;
            }

            $import->update(['sales' => $made, 'skipped' => $skipped]);

            return $import;
        });
    }

    /* ---------------------------------------------------------- private */

    /** How a file's item name is remembered: "paneer tikka|full". */
    public static function alias(string $item, ?string $size): string
    {
        return Str::limit(Str::lower(trim($item)).($size ? '|'.Str::lower(trim($size)) : ''), 200, '');
    }

    /**
     * The rows as bills: keyed by date and bill number, or by date alone.
     *
     * @return array<string, array{date: string, invoice: ?string, rows: list<array{item: string, size: ?string, qty: int, net: int, tax: int}>}>
     */
    private function bills(array $rows, array $layout): array
    {
        $bills = [];

        foreach ($rows as $number => $row) {
            $item = $row[$layout['item']] ?? '';

            if ($item === '') {
                continue;
            }

            $line = $number + 2;
            $date = self::date($row[$layout['date']] ?? '') ?? throw new RuntimeException("Row {$line}: \"".($row[$layout['date']] ?? '').'" is not a date ERP can read. Use 2026-10-01 or 01/10/2026.');

            try {
                $qty = Units::parse($row[$layout['qty']] ?? '0');
                $net = $layout['amount'] === null ? 0 : Decimal::mulDiv(Units::parse(($row[$layout['amount']] ?? '') ?: '0'), 100, Units::SCALE);
                $tax = $layout['tax'] === null ? 0 : Decimal::mulDiv(Units::parse(($row[$layout['tax']] ?? '') ?: '0'), 100, Units::SCALE);
            } catch (\InvalidArgumentException) {
                throw new RuntimeException("Row {$line}: the quantity or amount is not a number.");
            }

            if ($qty <= 0) {
                // A zero or a refund line: nothing was sold.
                continue;
            }

            $invoice = $layout['invoice'] === null ? null : (($row[$layout['invoice']] ?? '') ?: null);
            $ref = Str::limit('csv:'.$date.($invoice ? ':'.$invoice : ''), 100, '');

            $bills[$ref] ??= ['date' => $date, 'invoice' => $invoice, 'rows' => []];
            $bills[$ref]['rows'][] = [
                'item' => $item,
                'size' => $layout['size'] === null ? null : (($row[$layout['size']] ?? '') ?: null),
                'qty' => $qty,
                'net' => max(0, $net),
                'tax' => max(0, $tax),
            ];
        }

        return $bills;
    }

    /**
     * The sales item every name in the file stands for: what the person
     * chose, what was remembered, or a new sales item - and remember it.
     *
     * @return array<string, array{sales_item_id: int, sales_item_size_id: int}>
     */
    private function targets(string $outletId, string $tenant, array $rows, array $layout, array $matches): array
    {
        $targets = [];

        foreach ($this->names($outletId, $rows, $layout) as $name) {
            $chosen = $matches[$name['alias']] ?? null;
            $itemId = $chosen['sales_item_id'] ?? $name['sales_item_id'];
            $sizeId = (int) ($chosen['sales_item_size_id'] ?? $name['sales_item_size_id']);

            $item = $itemId === null ? null : SalesItem::allOutlets()->where('outlet_id', $outletId)->find($itemId);

            if ($item === null) {
                [$item] = $this->menu->outside($tenant, $outletId, SalesItem::CSV, $name['alias'],
                    trim($name['item'].($name['size'] ? ' ('.$name['size'].')' : '')));
                $sizeId = 0;
            }

            DB::table('sales_item_aliases')->updateOrInsert(
                ['outlet_id' => $outletId, 'alias' => $name['alias']],
                ['sales_item_id' => $item->id, 'sales_item_size_id' => $sizeId, 'updated_at' => now(), 'created_at' => now()],
            );

            $targets[$name['alias']] = ['sales_item_id' => $item->id, 'sales_item_size_id' => $sizeId];
        }

        return $targets;
    }

    private function rememberLayout(string $outletId, string $tenant, array $headers, array $layout): void
    {
        $byHeading = [];

        foreach ($layout as $field => $index) {
            if ($index !== null && isset($headers[$index])) {
                $byHeading[$field] = $headers[$index];
            }
        }

        OutletSetting::query()->updateOrCreate(['outlet_id' => $outletId], ['tenant_id' => $tenant, 'csv_layout' => $byHeading]);
    }

    /** 2026-10-01, 01/10/2026, 01-10-2026, 1 Oct 2026 - day first, as India writes it. */
    private static function date(string $text): ?string
    {
        $text = trim($text);

        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'd.m.Y', 'd/m/y', 'd-m-y', 'j M Y', 'd M Y', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $text);

            if ($date !== false && $date->format($format) === $text) {
                return $date->format('Y-m-d');
            }
        }

        // "2026-10-01 13:45:00" and the like.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]/', $text, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
