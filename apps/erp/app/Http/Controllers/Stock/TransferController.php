<?php

namespace App\Http\Controllers\Stock;

use App\Models\StockTransfer;
use App\Models\Store;
use App\Services\Transfers;
use App\Support\Here;
use App\Support\Lines;
use App\Support\Outlets;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stock sent to, and come from, the business's other outlets.
 *
 * One list for both directions, with what is waiting to be received at the
 * top. Sending is one form; receiving is the transfer's own page, which
 * arrives filled with everything that was sent, so a crate that came whole
 * is one button.
 */
final class TransferController
{
    public function index(): View
    {
        $outlet = Here::outlet();

        return view('transfers.index', [
            'outlet' => $outlet,
            'others' => Outlets::others($outlet),
            'awaited' => StockTransfer::query()->awaitedAt($outlet)->with('lines.item')->oldest('id')->get(),
            'transfers' => StockTransfer::query()->touching($outlet)
                ->with(['lines.item', 'user'])
                ->latest('id')
                ->paginate(30),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        $others = Outlets::others(Here::outlet());

        if ($others->isEmpty()) {
            return redirect()->route('transfers.index');
        }

        return view('transfers.create', [
            'others' => $others,
            'stores' => Here::stores(),
            'from' => Here::mainStores()['main'],
            'today' => Here::businessDate(),
            'catalogue' => Lines::catalogue(),
        ]);
    }

    public function store(Request $request, Transfers $transfers): RedirectResponse
    {
        $outlet = Here::outlet();

        $data = $request->validate([
            'to_outlet_id' => ['required', 'string', Rule::in(Outlets::others($outlet)->pluck('id')->all())],
            'from_store_id' => ['required', 'integer'],
            'on_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['to_outlet_id.in' => 'Choose one of your other outlets.']);

        $lines = Lines::from($request);

        try {
            $transfer = $transfers->send(
                $outlet,
                Store::forOutlet($outlet)->findOrFail($data['from_store_id']),
                $data['to_outlet_id'],
                date('Y-m-d', strtotime($data['on_date'])),
                $lines,
                $data['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.show', $transfer->id)
            ->with('status', $transfer->number.' sent to '.$transfer->toName().'. It leaves your stock now and joins theirs when they receive it.');
    }

    public function show(int $transfer): View
    {
        $outlet = Here::outlet();
        $transfer = $this->find($transfer)->load(['lines.item', 'fromStore', 'toStore', 'user', 'receiver']);

        return view('transfers.show', [
            'transfer' => $transfer,
            'incoming' => $transfer->isComingTo($outlet),
            'stores' => $transfer->isComingTo($outlet) && $transfer->isOnItsWay() ? Here::stores() : collect(),
            'into' => Here::mainStores()['main'],
            'today' => Here::businessDate(),
        ]);
    }

    /**
     * What arrived is typed line by line, in kilos, litres or pieces, and
     * each box starts at what was sent.
     */
    public function receive(Request $request, int $transfer, Transfers $transfers): RedirectResponse
    {
        $outlet = Here::outlet();
        $transfer = $this->find($transfer)->load('lines.item');

        abort_unless($transfer->isComingTo($outlet), 403, 'A transfer is received at the outlet it was sent to.');

        $data = $request->validate([
            'to_store_id' => ['required', 'integer'],
            'on_date' => ['required', 'date'],
            'received' => ['nullable', 'array'],
        ]);

        $received = [];

        foreach ($transfer->lines as $line) {
            $typed = trim((string) ($data['received'][$line->id] ?? ''));

            // Left blank, or left as the form drew it: it arrived whole.
            if ($typed === '' || $typed === Units::inBig($line->qty, $line->item->base_unit)) {
                continue;
            }

            try {
                $received[$line->id] = Units::toBase($typed, Units::big($line->item->base_unit), $line->item->base_unit);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["received.{$line->id}" => $line->item->name.': that is not a number.']);
            }

            if ($received[$line->id] < 0 || $received[$line->id] > $line->qty) {
                throw ValidationException::withMessages([
                    "received.{$line->id}" => $line->item->name.': '.$line->item->show($line->qty).' was sent, so that is the most that can have arrived.',
                ]);
            }
        }

        try {
            $transfer = $transfers->receive(
                $transfer,
                Store::forOutlet($outlet)->findOrFail($data['to_store_id']),
                date('Y-m-d', strtotime($data['on_date'])),
                $received,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.show', $transfer->id)->with('status', $transfer->short_paise > 0
            ? $transfer->number.' received. What did not arrive, worth Rs '.Units::rupees($transfer->short_paise).', is written off against it.'
            : $transfer->number.' received: it is in your stock now.');
    }

    public function cancel(int $transfer, Transfers $transfers): RedirectResponse
    {
        $transfer = $this->find($transfer);

        abort_unless($transfer->from_outlet_id === Here::outlet(), 403, 'A transfer is cancelled by the outlet that sent it.');

        try {
            $transfers->cancel($transfer);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('transfers.show', $transfer->id)->with('status', $transfer->number.' cancelled. The stock is back on your shelf.');
    }

    /** A transfer this outlet sent or is being sent - nobody else's. */
    private function find(int $transfer): StockTransfer
    {
        return StockTransfer::query()->touching(Here::outlet())->findOrFail($transfer);
    }
}
