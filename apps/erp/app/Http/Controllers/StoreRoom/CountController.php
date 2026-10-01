<?php

namespace App\Http\Controllers\StoreRoom;

use App\Models\StockDocument;
use App\Models\StockLevel;
use App\Models\Store;
use App\Services\StoreRoom;
use App\Support\Here;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Counting stock: the shelf against the books.
 *
 * The form lists what the books say one store holds. Whoever is counting
 * types what is actually there against the rows they counted and leaves the
 * rest blank - a blank is "not counted", never "none". Saving sets each
 * counted slot to what was found and books only the difference.
 *
 * The first count of a new outlet is its opening stock: tick the box and
 * give each item a cost, and the stock arrives at that cost rather than as
 * an unexplained gain.
 */
final class CountController
{
    public function index(): View
    {
        return view('counts.index', [
            'counts' => StockDocument::forOutlet(Here::outlet())
                ->with(['store', 'user'])
                ->withCount('lines')
                ->where('kind', StockDocument::COUNT)
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(Request $request): View
    {
        $outlet = Here::outlet();
        $stores = Here::stores();
        $store = $stores->firstWhere('id', (int) $request->query('store')) ?? Here::mainStores()['main'];
        $everything = $request->boolean('all');

        $levels = StockLevel::forOutlet($outlet)->with('batch')->where('store_id', $store->id)->get()->groupBy('stock_item_id');

        // One row per slot: an item with batches is counted batch by batch.
        $rows = [];

        foreach (Here::items()->active()->orderBy('category')->orderBy('name')->get() as $item) {
            $slots = ($levels[$item->id] ?? collect())->filter(fn (StockLevel $level) => $level->qty !== 0);

            if ($slots->isEmpty()) {
                if ($everything) {
                    $rows[] = ['item' => $item, 'batch' => null, 'batch_id' => 0, 'expected' => 0];
                }

                continue;
            }

            foreach ($slots->sortBy('batch_id') as $level) {
                $rows[] = ['item' => $item, 'batch' => $level->batch, 'batch_id' => $level->batch_id, 'expected' => $level->qty];
            }
        }

        return view('counts.create', [
            'stores' => $stores,
            'store' => $store,
            'rows' => $rows,
            'everything' => $everything,
            'today' => Here::businessDate(),
        ]);
    }

    public function store(Request $request, StoreRoom $room): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'on_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'opening' => ['nullable', 'boolean'],
            'rows' => ['required', 'array'],
        ]);

        $items = Here::items()->with('packs')->whereIn('id', array_column($data['rows'], 'item'))->get()->keyBy('id');
        $counted = [];

        foreach ($data['rows'] as $index => $row) {
            $typed = trim((string) ($row['qty'] ?? ''));
            $item = $items[(int) ($row['item'] ?? 0)] ?? null;

            // A blank is "not counted".
            if ($typed === '' || $item === null) {
                continue;
            }

            try {
                $qty = Units::toBase($typed, Units::big($item->base_unit), $item->base_unit);
                $price = trim((string) ($row['price'] ?? ''));
                $rate = $price === '' ? null : Units::rateFromQuote($price, $item->base_unit);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["rows.{$index}.qty" => $item->name.': that is not a number.']);
            }

            if ($qty < 0) {
                throw ValidationException::withMessages(["rows.{$index}.qty" => $item->name.': a count cannot be below zero.']);
            }

            $counted[] = ['item' => $item, 'batch_id' => (int) ($row['batch_id'] ?? 0), 'qty' => $qty, 'cost_rate' => $rate];
        }

        if ($counted === []) {
            throw ValidationException::withMessages(['rows' => 'Type what you counted against at least one item.']);
        }

        $count = $room->count(
            $outlet,
            Store::forOutlet($outlet)->findOrFail($data['store_id']),
            date('Y-m-d', strtotime($data['on_date'])),
            $counted,
            $data['note'] ?? null,
            (bool) ($data['opening'] ?? false),
        );

        return redirect()->route('counts.show', $count->id)
            ->with('status', $count->number.' posted: '.count($counted).' item'.(count($counted) === 1 ? '' : 's').' counted.');
    }

    public function show(int $count): View
    {
        return view('counts.show', [
            'count' => StockDocument::forOutlet(Here::outlet())
                ->with(['store', 'user', 'lines.item', 'lines.batch'])
                ->where('kind', StockDocument::COUNT)
                ->findOrFail($count),
        ]);
    }
}
