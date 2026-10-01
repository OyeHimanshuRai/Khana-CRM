<?php

namespace App\Services;

use App\Support\Units;

/**
 * A report as rows for a spreadsheet: the same figures the screen shows,
 * from the same methods, laid out as one plain table.
 *
 * Made to be added up by whoever opens it. So money is a bare number of
 * rupees and a quantity a bare number of kilos, litres or pieces with its
 * unit in the column beside it - never "Rs 1,250.00" or "12.5 kg", which a
 * spreadsheet reads as words. Totals are left out for the same reason: they
 * are one SUM away, and a total row gets sorted into the middle.
 */
class ReportExport
{
    public function __construct(private readonly Reports $reports) {}

    /**
     * @return array{0: list<string>, 1: list<list<string|int>>} the headings, then the rows
     */
    public function table(string $report, string $outletId, string $from, string $to): array
    {
        return match ($report) {
            'food-cost' => $this->foodCost($outletId, $from, $to),
            'variance' => $this->variance($outletId, $from, $to),
            'stock-value' => $this->stockValue($outletId),
            'expiry' => $this->expiry($outletId),
            'purchases' => $this->purchases($outletId, $from, $to),
            'payables' => $this->payables($outletId),
        };
    }

    private function foodCost(string $outletId, string $from, string $to): array
    {
        return [
            ['Dish', 'Portions sold', 'Sold for (Rs)', 'Food cost (Rs)', 'Food cost %', 'Left over (Rs)'],
            $this->reports->foodCost($outletId, $from, $to)['dishes']->map(fn (array $dish) => [
                $dish['name'],
                Units::plain($dish['qty']),
                self::rupees($dish['net']),
                // No recipe is no cost on record - a blank, not a zero.
                $dish['cost'] > 0 ? self::rupees($dish['cost']) : '',
                $dish['percent'] ?? '',
                self::rupees($dish['net'] - $dish['cost']),
            ])->all(),
        ];
    }

    private function variance(string $outletId, string $from, string $to): array
    {
        return [
            ['Item', 'Unit', 'Came in', 'Recipes used', 'Wasted', 'Sent to other outlets', 'Counts found', 'Counts found (Rs)'],
            $this->reports->variance($outletId, $from, $to)->map(fn (array $row) => [
                $row['item']->name,
                Units::big($row['item']->base_unit),
                Units::inBig($row['received'], $row['item']->base_unit),
                Units::inBig($row['used'], $row['item']->base_unit),
                Units::inBig($row['wasted'], $row['item']->base_unit),
                Units::inBig($row['sent'], $row['item']->base_unit),
                Units::inBig($row['difference'], $row['item']->base_unit),
                self::rupees($row['difference_value']),
            ])->all(),
        ];
    }

    private function stockValue(string $outletId): array
    {
        $rows = [];

        foreach ($this->reports->stockValue($outletId)['categories'] as $category) {
            foreach ($category['items'] as $row) {
                $rows[] = [
                    $category['name'],
                    $row['item']->name,
                    Units::big($row['item']->base_unit),
                    Units::inBig($row['qty'], $row['item']->base_unit),
                    self::rupees($row['value']),
                ];
            }
        }

        return [['Category', 'Item', 'Unit', 'On hand', 'Worth (Rs)'], $rows];
    }

    private function expiry(string $outletId): array
    {
        return [
            ['Item', 'Batch', 'Expires', 'Days left', 'Unit', 'On hand', 'Worth (Rs)'],
            $this->reports->expiry($outletId)->map(fn (array $row) => [
                (string) $row['batch']->item?->name,
                $row['batch']->batch_no,
                $row['batch']->expiry_date->toDateString(),
                $row['days'],
                Units::big($row['batch']->item?->base_unit ?? 'pc'),
                Units::inBig($row['qty'], $row['batch']->item?->base_unit ?? 'pc'),
                self::rupees($row['value']),
            ])->all(),
        ];
    }

    private function purchases(string $outletId, string $from, string $to): array
    {
        return [
            ['Date', 'Receipt', 'Supplier', 'Supplier bill', 'Item', 'Unit', 'Quantity', 'Before tax (Rs)', 'Tax (Rs)', 'Total (Rs)'],
            $this->reports->purchaseRegister($outletId, $from, $to)->map(fn (object $line) => [
                substr((string) $line->received_on, 0, 10),
                $line->number,
                (string) $line->supplier,
                (string) $line->bill_no,
                $line->item,
                Units::big($line->base_unit),
                Units::inBig((int) $line->qty, $line->base_unit),
                self::rupees((int) $line->net_paise),
                self::rupees((int) $line->tax_paise),
                self::rupees((int) $line->net_paise + (int) $line->tax_paise),
            ])->all(),
        ];
    }

    private function payables(string $outletId): array
    {
        return [
            ['Supplier', 'Not yet due (Rs)', 'Up to 30 days late (Rs)', 'Over 30 days late (Rs)', 'Paid in advance (Rs)', 'You owe (Rs)'],
            $this->reports->payables($outletId)['suppliers']->map(fn (array $row) => [
                $row['supplier']->name,
                self::rupees($row['not_due']),
                self::rupees($row['late']),
                self::rupees($row['very_late']),
                self::rupees($row['advance']),
                self::rupees($row['owed']),
            ])->all(),
        ];
    }

    /** Paise as a number of rupees a spreadsheet will add up: "1250.50". */
    private static function rupees(int $paise): string
    {
        return ($paise < 0 ? '-' : '').intdiv(abs($paise), 100).'.'.str_pad((string) (abs($paise) % 100), 2, '0', STR_PAD_LEFT);
    }
}
