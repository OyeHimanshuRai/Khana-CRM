<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Shop;
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

    /** Seconds within which an identical print request is the same print. */
    private const DUPLICATE_WINDOW = 10;

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
        /*
         | A double-clicked button, a retried request, a repeated event: the
         | same document for the same station within a few seconds is one
         | print, not two. A deliberate reprint is exempt - its whole point is
         | a second copy - and so is a failed first attempt, which printed
         | nothing to be a duplicate of.
         */
        if (! $reprint) {
            $recent = PrintJob::query()
                ->forSame($printable, $kind)
                ->where('kitchen_station_id', $station?->id)
                ->whereIn('status', [PrintJob::QUEUED, PrintJob::SENT])
                ->where('is_reprint', false)
                ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_WINDOW))
                ->latest('id')
                ->first();

            if ($recent !== null) {
                return $recent;
            }
        }

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
     * Try a failed job again.
     *
     * Append-only, like everything in the log: the failed row stays failed
     * and the retry is a new row. It is not a reprint - nothing came out the
     * first time - so it is not marked as one.
     *
     * @throws RuntimeException when the job did not fail, its document is gone,
     *                          or there is no longer a network printer to send to
     */
    public function retry(PrintJob $failed): PrintJob
    {
        if ($failed->status !== PrintJob::FAILED) {
            throw new RuntimeException('Only a failed print can be retried.');
        }

        $printable = $failed->printable;

        if ($printable === null) {
            throw new RuntimeException('The document for this print no longer exists.');
        }

        // Asked before send(), which would log a queued job nobody can finish.
        if (! $this->printerFor($failed->kind, $failed->station)?->isAutomatic()) {
            throw new RuntimeException('That document no longer has a network printer to go to.');
        }

        return $this->send($printable, $failed->kind, $failed->station, false, 'Retry of #'.$failed->id);
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

    /**
     * A customer bill, for a network bill printer.
     *
     * Reads the invoice's own copied fields and never recalculates: what the
     * guest is handed must match the stored invoice to the paisa. Long item
     * names wrap under their own line rather than being cut, so the quantity
     * and amount stay on the line that carries them.
     */
    private function renderBill(Model $invoice, Printer $printer, bool $reprint): string
    {
        if (! $invoice instanceof Invoice) {
            throw new RuntimeException('A bill can only be printed for an invoice.');
        }

        $invoice->loadMissing('items');

        $paper = new EscPos($printer->columns);

        $shop = Shop::withTrashed()->find($invoice->shop_id);

        if ($reprint) {
            $paper->centre('** DUPLICATE **');
        }

        $paper->big($shop?->name ?? config('app.name'));

        if ($shop?->addressLine()) {
            $paper->centre($shop->addressLine());
        }

        if ($shop?->phone) {
            $paper->centre('Ph '.$shop->phone);
        }

        foreach (['GSTIN' => $shop?->gstin, 'FSSAI' => $shop?->fssai_no] as $label => $value) {
            if (filled($value)) {
                $paper->centre($label.' '.$value);
            }
        }

        // Number and date on their own lines: together they are wider than a
        // 58mm roll, and the part a clerk reads back (the number) must never
        // be the part that is cut.
        $paper->rule()
            ->bold('Bill '.$invoice->number)
            ->line($invoice->invoiced_at?->format('d/m/Y H:i') ?? '');

        if ($invoice->isCancelled()) {
            $paper->big('CANCELLED');
        }

        $paper->rule();

        foreach ($invoice->items as $line) {
            // Name on its own (wrapped) line, numbers beneath: a long dish
            // name can never push the amount off the paper.
            $paper->line($line->product_name);
            $paper->columns(
                '  '.$line->quantityLabel().' x '.number_format((float) $line->unit_price, 2),
                number_format((float) $line->line_total, 2),
            );
        }

        $paper->rule()
            ->columns('Sub-total', number_format((float) $invoice->subtotal, 2));

        $discount = (float) $invoice->line_discount_total + (float) $invoice->invoice_discount;

        if ($discount > 0) {
            $paper->columns('Discount', '-'.number_format($discount, 2));
        }

        if ((float) $invoice->tax_total > 0) {
            if ($invoice->is_inter_state) {
                $paper->columns('IGST', number_format((float) $invoice->igst_total, 2));
            } else {
                $paper->columns('CGST', number_format((float) $invoice->cgst_total, 2))
                    ->columns('SGST', number_format((float) $invoice->sgst_total, 2));
            }
        }

        if (abs((float) $invoice->round_off) > 0.004) {
            $paper->columns('Round off', number_format((float) $invoice->round_off, 2));
        }

        $paper->rule()
            ->raw(EscPos::BOLD_ON)
            ->columns('TOTAL', number_format((float) $invoice->grand_total, 2))
            ->raw(EscPos::BOLD_OFF);

        foreach ($invoice->payments()->where('status', '!=', Payment::CANCELLED)->get() as $payment) {
            $paper->columns($payment->methodLabel(), number_format((float) $payment->amount, 2));
        }

        if ((float) $invoice->due_total > 0) {
            $paper->bold('Balance due '.number_format((float) $invoice->due_total, 2));
        }

        return $paper->rule()
            ->centre('Thank you. Visit again.')
            ->feed(2)
            ->cut($printer->auto_cut)
            ->bytes();
    }
}
