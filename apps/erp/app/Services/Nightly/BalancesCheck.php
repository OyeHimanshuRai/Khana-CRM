<?php

namespace App\Services\Nightly;

use App\Models\StockLevel;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Services\StockService;
use App\Support\Units;

/**
 * The nightly balance check: two figures ERP stores that must always equal
 * what they are the sum of.
 *
 *   - a stock level, and the movements of its slot;
 *   - what a supplier bill says has been paid, and the payments made
 *     against it.
 *
 * Neither should ever differ: StockService writes a level and its movement
 * together, and Purchasing a bill and its payment. This proves it each
 * night, and says where on the night it stops being true.
 *
 * A difference is never corrected here. Either figure might be the wrong
 * one, and a balance quietly rewritten is worse than one known to be off -
 * so it is named, for a person to look into.
 */
final class BalancesCheck
{
    /** How many differences are spelt out; the rest are counted. */
    private const NAMED = 5;

    public function __construct(private readonly StockService $stock) {}

    public function run(string $outletId): Finding
    {
        $levels = $this->stock->reconcile($outletId);
        $bills = $this->bills($outletId);

        if ($levels === [] && $bills === []) {
            return Finding::ok('Every stock level equals its movements, and every supplier bill equals its payments.');
        }

        $said = [];

        if ($levels !== []) {
            $said[] = self::count($levels, 'stock level does', 'stock levels do').' not equal the movements behind '
                .(count($levels) === 1 ? 'it' : 'them').': '.self::named(array_map($this->level(...), array_slice($levels, 0, self::NAMED)), count($levels)).'.';
        }

        if ($bills !== []) {
            $said[] = self::count($bills, 'supplier bill does', 'supplier bills do').' not equal the payments made against '
                .(count($bills) === 1 ? 'it' : 'them').': '.self::named(array_slice($bills, 0, self::NAMED), count($bills)).'.';
        }

        return Finding::problem(count($levels) + count($bills), 0, implode(' ', $said).' Nothing was changed.');
    }

    /**
     * Every bill of the outlet whose paid figure is not the sum of its
     * payments, in words.
     *
     * @return list<string>
     */
    private function bills(string $outletId): array
    {
        $bills = SupplierBill::allOutlets()->with('supplier')->where('outlet_id', $outletId)->orderBy('id')->get();

        $paid = SupplierPayment::allOutlets()
            ->whereIn('supplier_bill_id', $bills->pluck('id'))
            ->groupBy('supplier_bill_id')
            ->selectRaw('supplier_bill_id, SUM(amount_paise) as total')
            ->pluck('total', 'supplier_bill_id');

        $wrong = [];

        foreach ($bills as $bill) {
            $payments = (int) ($paid[$bill->id] ?? 0);

            if ($payments !== $bill->paid_paise) {
                $wrong[] = 'bill '.$bill->bill_no.' from '.($bill->supplier?->name ?? 'an unknown supplier')
                    .' says ₹'.Units::rupees($bill->paid_paise).' paid, its payments add up to ₹'.Units::rupees($payments);
            }
        }

        return $wrong;
    }

    /** @param  array{level_id: int, level: int, movements: int}  $row */
    private function level(array $row): string
    {
        $level = StockLevel::allOutlets()->with(['item', 'store'])->find($row['level_id']);
        $base = $level?->item?->base_unit ?? 'pc';

        return ($level?->item?->name ?? 'an item').' in '.($level?->store?->name ?? 'a store')
            .' says '.Units::show($row['level'], $base).', its movements add up to '.Units::show($row['movements'], $base);
    }

    /** @param  list<mixed>  $rows */
    private static function count(array $rows, string $one, string $many): string
    {
        return count($rows).' '.(count($rows) === 1 ? $one : $many);
    }

    /** @param  list<string>  $named */
    private static function named(array $named, int $all): string
    {
        return implode('; ', $named).($all > count($named) ? '; and '.($all - count($named)).' more' : '');
    }
}
