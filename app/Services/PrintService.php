<?php

namespace App\Services;

use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Support\CurrentShop;
use App\Support\EscPos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

/**
 * Getting paper out of the right machine (SRS 6, 8, 21).
 *
 * ---------------------------------------------------------------------------
 * A print is never allowed to break the thing being printed
 * ---------------------------------------------------------------------------
 *
 * This is the rule the whole class is arranged around. An order has been
 * taken, a bill has been raised, a kitchen ticket has been bumped - and then
 * the printer is switched off, or its cable is out, or somebody typed the
 * wrong IP three months ago.
 *
 * None of that may become an exception in front of a guest. Every failure
 * here ends as a `failed` PrintJob with the reason on it, and the caller is
 * told what happened rather than thrown at. A restaurant can hand-write a
 * ticket; it cannot un-take an order.
 *
 * ---------------------------------------------------------------------------
 * Which printer
 * ---------------------------------------------------------------------------
 *
 * KitchenRouter already decides which station a dish belongs to. This decides
 * which box that station's paper comes out of, and the search is deliberately
 * narrow-to-broad: the station's own printer, then the shop's default KOT
 * printer, then nothing. "Nothing" is a normal answer - it means browser
 * printing, which is what this system did before printers existed.
 */
class PrintService
{
    /** How long to wait for a printer that may simply be switched off. */
    private const TIMEOUT = 3;

    /**
     * Send a kitchen ticket to whichever printers should have it.
     *
     * A ticket split across a tandoor and a bar prints twice, and the two
     * jobs are not duplicates - each carries only its own station's lines,
     * which is the entire point of station routing.
     *
     * @return Collection<int, PrintJob>
     */
    public function kot(Order $order, ?KitchenStation $station = null, bool $reprint = false, ?string $reason = null): Collection
    {
        $order->loadMissing(['items.kitchenStation', 'items.modifiers', 'tableSession.table']);

        $stations = $station !== null
            ? collect([$station])
            : $order->items
                ->pluck('kitchenStation')
                ->filter()
                ->unique('id')
                ->values();

        $jobs = new Collection();

        /*
         | A ticket with no station at all still prints. That is a counter
         | sale in a shop that never configured a kitchen, and it must not
         | silently produce no paper.
         */
        if ($stations->isEmpty()) {
            $jobs->push($this->send($order, Printer::KOT, null, $reprint, $reason));

            return $jobs;
        }

        foreach ($stations as $one) {
            $jobs->push($this->send($order, Printer::KOT, $one, $reprint, $reason));
        }

        return $jobs;
    }

    /**
     * Print one document, and record the attempt whatever happens.
     */
    public function send(
        Model $printable,
        string $kind,
        ?KitchenStation $station = null,
        bool $reprint = false,
        ?string $reason = null,
    ): PrintJob {
        $printer = $this->printerFor($kind, $station);

        $job = PrintJob::create([
            'shop_id' => $printable->getAttribute('shop_id') ?? CurrentShop::idForWrite(),
            'printer_id' => $printer?->id,
            'printable_type' => $printable->getMorphClass(),
            'printable_id' => $printable->getKey(),
            'kind' => $kind,
            'kitchen_station_id' => $station?->id,
            'copies' => $printer->copies ?? 1,
            'is_reprint' => $reprint,
            'reason' => $reason,
            'status' => PrintJob::QUEUED,
            'user_id' => Auth::id(),
        ]);

        /*
         | No printer, or a browser one: there is nothing to send to. The job
         | stays queued, which is the honest record - somebody at a screen
         | still has to press print, and this system will never learn whether
         | they did.
         */
        if ($printer === null || ! $printer->isAutomatic()) {
            return $job;
        }

        try {
            $bytes = $this->render($printable, $kind, $station, $printer, $reprint);

            $this->write($printer, str_repeat($bytes, max(1, $printer->copies)));

            $job->forceFill([
                'status' => PrintJob::SENT,
                'bytes' => strlen($bytes) * max(1, $printer->copies),
            ])->save();

            $printer->forceFill(['last_used_at' => now()])->save();
        } catch (Throwable $e) {
            /*
             | Recorded, reported, and not rethrown. See the class comment:
             | the order is already taken and the guest is already waiting.
             */
            $job->forceFill([
                'status' => PrintJob::FAILED,
                'error' => mb_substr($e->getMessage(), 0, 255),
            ])->save();

            report($e);
        }

        return $job->refresh();
    }

    /**
     * The printer that should take this, or null for browser printing.
     *
     * Narrow to broad, and null is a normal answer.
     */
    public function printerFor(string $kind, ?KitchenStation $station = null): ?Printer
    {
        $base = Printer::query()->active()->ofKind($kind);

        if ($station !== null) {
            $own = (clone $base)->where('kitchen_station_id', $station->id)->first();

            if ($own !== null) {
                return $own;
            }
        }

        /*
         | The shop's default for this kind. Deliberately excludes printers
         | tied to a *different* station: a tandoor's printer is not the
         | fallback for the bar, and sending drink orders to the tandoor is
         | worse than sending them nowhere.
         */
        return (clone $base)
            ->where(fn ($q) => $q->whereNull('kitchen_station_id')->orWhere('is_default', true))
            ->orderByDesc('is_default')
            ->first();
    }

    /* ----------------------------------------------------------- the wire */

    /**
     * Open a socket to the printer and write.
     *
     * Raw TCP on port 9100, which is what every thermal printer in this
     * market speaks. No library, because there is nothing here a library
     * would do differently - and one more dependency in the path between a
     * cook and their ticket is not worth it.
     *
     * @throws RuntimeException with a message a person can act on
     */
    private function write(Printer $printer, string $bytes): void
    {
        $socket = @fsockopen($printer->host, $printer->port, $code, $message, self::TIMEOUT);

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'Could not reach %s at %s — %s. Check it is switched on and on the same network.',
                $printer->name,
                $printer->addressLabel(),
                $message ?: 'no answer',
            ));
        }

        try {
            stream_set_timeout($socket, self::TIMEOUT);

            $written = @fwrite($socket, $bytes);

            if ($written === false || $written < strlen($bytes)) {
                throw new RuntimeException($printer->name.' accepted the connection but not the whole ticket.');
            }
        } finally {
            fclose($socket);
        }
    }

    /* -------------------------------------------------------- the paper */

    /** @throws RuntimeException */
    private function render(Model $printable, string $kind, ?KitchenStation $station, Printer $printer, bool $reprint): string
    {
        if ($kind === Printer::KOT && $printable instanceof Order) {
            return $this->renderKot($printable, $station, $printer, $reprint);
        }

        if ($kind === Printer::BILL) {
            return $this->renderBill($printable, $printer, $reprint);
        }

        throw new RuntimeException('There is no network layout for a "'.$kind.'" yet.');
    }

    /**
     * A kitchen ticket.
     *
     * Laid out for somebody reading it at arm's length, in a hurry, in a hot
     * room: the station and the table are the biggest things on it, the
     * quantities are on the left where the eye starts, and prices are absent
     * because a kitchen has no use for them.
     */
    private function renderKot(Order $order, ?KitchenStation $station, Printer $printer, bool $reprint): string
    {
        $paper = new EscPos($printer->columns);

        if ($reprint) {
            // First, and unmissable. A second copy of a ticket is how a dish
            // gets made twice.
            $paper->big('** REPRINT **')->feed();
        }

        $paper->big($station?->name ?? 'KITCHEN');

        $table = $order->tableSession?->table;

        $paper->centre($table !== null ? 'Table '.$table->name : ucfirst((string) $order->order_type))
            ->rule()
            ->columns($order->order_number, $order->created_at?->format('g:i a') ?? '')
            ->rule();

        $lines = $station !== null
            ? $order->items->where('kitchen_station_id', $station->id)
            : $order->items;

        foreach ($lines as $line) {
            $paper->bold(rtrim((string) $line->quantity, '0.').' x '.$line->product_name);

            foreach ($line->modifiers as $modifier) {
                $paper->line('   + '.$modifier->name);
            }

            if (filled($line->note)) {
                // The line a cook must not miss, so it is not indented away.
                $paper->bold('   ! '.$line->note);
            }
        }

        $paper->rule();

        if (filled($order->note)) {
            $paper->bold('NOTE: '.$order->note);
        }

        return $paper->feed(2)->cut($printer->auto_cut)->bytes();
    }

    /** A customer bill, for a network bill printer. */
    private function renderBill(Model $invoice, Printer $printer, bool $reprint): string
    {
        $paper = new EscPos($printer->columns);

        $shop = CurrentShop::get();

        $paper->big($shop?->name ?? config('app.name'));

        if ($reprint) {
            $paper->centre('** REPRINT **');
        }

        $paper->centre($invoice->getAttribute('invoice_number') ?? '')
            ->centre($invoice->getAttribute('invoiced_at')?->format('j M Y, g:i a') ?? '')
            ->rule();

        foreach ($invoice->getAttribute('items') ?? [] as $line) {
            $paper->columns(
                rtrim((string) $line->quantity, '0.').' x '.$line->product_name,
                number_format((float) $line->line_total, 2),
            );
        }

        $paper->rule()
            ->columns('TOTAL', number_format((float) $invoice->getAttribute('grand_total'), 2))
            ->rule()
            ->centre('Thank you');

        return $paper->feed(2)->cut($printer->auto_cut)->bytes();
    }
}
