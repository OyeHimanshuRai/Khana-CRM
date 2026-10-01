<?php

namespace App\Support;

use App\Models\StockItem;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Khana\Kit\Support\Decimal;

/**
 * The rows of an item-lines form (resources/views/partials/lines.blade.php),
 * read into something a service can use.
 *
 * Receiving, issuing, counting, wastage, an order, a recipe - each is "these
 * items, this much of each", typed as an item, a quantity and a unit. This
 * turns the rows into stock items and stored quantities, leaves out the rows
 * left blank, and says which row is wrong in words the person can act on.
 */
final class Lines
{
    /**
     * @param  bool  $allowZero  a count of zero is an answer; a receipt of zero is a blank row
     * @return list<array{item: StockItem, qty: int, entered_qty: string, unit: string, amount_paise: int, batch_no: ?string, expiry: ?string, batch_id: int, note: ?string, optional: bool}>
     *
     * @throws ValidationException
     */
    public static function from(Request $request, string $key = 'lines', bool $allowZero = false, bool $required = true): array
    {
        $rows = (array) $request->input($key, []);
        $ids = array_filter(array_map(fn ($row) => (int) ($row['item'] ?? 0), $rows));
        $items = Here::items()->with('packs')->whereIn('id', $ids)->get()->keyBy('id');

        $lines = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $itemId = (int) ($row['item'] ?? 0);
            $typed = trim((string) ($row['qty'] ?? ''));

            if ($itemId === 0 || $typed === '') {
                continue;
            }

            $item = $items[$itemId] ?? null;

            if ($item === null) {
                $errors["{$key}.{$index}.item"] = 'That item is not in your stock list.';

                continue;
            }

            $unit = (string) ($row['unit'] ?? $item->base_unit);

            try {
                $qty = $item->toBase($typed, $unit);
                $price = trim((string) ($row['price'] ?? ''));
                // Rupees per the unit typed, times how many were typed.
                $amount = $price === '' ? 0 : Decimal::mulDiv(Units::parse($typed), Units::parse($price), 1_000_000);
            } catch (InvalidArgumentException $e) {
                $errors["{$key}.{$index}.qty"] = $item->name.': '.$e->getMessage();

                continue;
            }

            if ($qty < 0 || $amount < 0 || ($qty === 0 && ! $allowZero)) {
                $errors["{$key}.{$index}.qty"] = $item->name.': the quantity has to be above zero.';

                continue;
            }

            $expiry = trim((string) ($row['expiry'] ?? ''));

            if ($expiry !== '' && strtotime($expiry) === false) {
                $errors["{$key}.{$index}.expiry"] = $item->name.': that expiry date is not a date.';

                continue;
            }

            $lines[] = [
                'item' => $item,
                'qty' => $qty,
                'entered_qty' => Units::plain(Units::parse($typed)),
                'unit' => $unit,
                'amount_paise' => $amount,
                'batch_no' => filled($row['batch_no'] ?? null) ? trim((string) $row['batch_no']) : null,
                'expiry' => $expiry === '' ? null : date('Y-m-d', strtotime($expiry)),
                'batch_id' => (int) ($row['batch_id'] ?? 0),
                'note' => filled($row['note'] ?? null) ? trim((string) $row['note']) : null,
                'optional' => (bool) ($row['optional'] ?? false),
            ];
        }

        if ($errors === [] && $lines === [] && $required) {
            $errors[$key] = 'Add at least one item.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $lines;
    }

    /**
     * What the form's script needs to know about each item: its units, and
     * whether it wants a batch.
     *
     * @return array<int, array{name: string, base: string, units: array<string, string>, expiry: bool, price: string}>
     */
    public static function catalogue(bool $preparedOnly = false, bool $withoutPrepared = false): array
    {
        return Here::items()
            ->active()
            ->with('packs')
            ->when($preparedOnly, fn ($q) => $q->where('is_prepared', true))
            ->when($withoutPrepared, fn ($q) => $q->where('is_prepared', false))
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (StockItem $item) => [$item->id => [
                'name' => $item->name,
                'base' => $item->base_unit,
                'units' => $item->unitOptions(),
                'expiry' => $item->track_expiry,
                // What it last cost, per kg / litre / piece, as a hint.
                'price' => Units::rupees(Units::quote($item->last_cost_rate, $item->base_unit)['paise']),
            ]])
            ->all();
    }
}
