<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\CashRegister;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Raising the notifications SRS 15 asks for.
 *
 * The one door into `alerts`. Everything about it follows the lesson the
 * reminder engine already learned (docs/ERP-OVERVIEW section 6):
 *
 *   **Idempotent by construction, not by checking.** Every alert carries a
 *   dedupe key and is written through a unique index. Running the sweep twice,
 *   or resuming after a crash halfway through, updates rather than duplicates.
 *   A "have I done this already" query would race with itself; an index cannot.
 *
 *   **Addressed by right, not by person.** SRS 15's own table says "Low stock
 *   -> Branch Admin / Warehouse" - a role, not an individual. Addressing it to
 *   whoever happened to be on shift would hide it from whoever came in next, so
 *   an unaddressed alert carries the permission that gates it instead.
 *
 * This raises in-app alerts only. Email is ReminderService's and the campaign
 * engine's, and the two are deliberately separate: a mail outage must not lose
 * the in-app record, and an alert nobody emailed is still an alert.
 */
class AlertService
{
    /**
     * Raise one alert, or update the one that is already there.
     *
     * @param  string  $dedupeKey  what makes this alert *this* alert - usually
     *                             type + reference + the day. Two sweeps in one
     *                             day produce one row.
     */
    public function raise(
        Shop $shop,
        string $type,
        string $title,
        string $dedupeKey,
        ?string $body = null,
        ?string $link = null,
        ?Model $reference = null,
        ?string $can = null,
        ?User $user = null,
        string $level = 'info',
        ?\DateTimeInterface $expiresAt = null,
    ): Alert {
        return DB::transaction(function () use (
            $shop, $type, $title, $dedupeKey, $body, $link,
            $reference, $can, $user, $level, $expiresAt
        ) {
            /** @var Alert $alert */
            $alert = Alert::allShops()->firstOrNew([
                'shop_id' => $shop->id,
                'dedupe_key' => $dedupeKey,
            ]);

            /*
             | An existing alert keeps its read state. Somebody who has seen
             | "this item is out of stock" should not have it march back into
             | their unread count every morning - the alert is still
             | true and still listed; it has simply stopped being news.
             */
            $alert->forceFill([
                'shop_id' => $shop->id,
                'dedupe_key' => $dedupeKey,
                'user_id' => $user?->getKey(),
                'can' => $can,
                'type' => $type,
                'level' => $level,
                'title' => $title,
                'body' => $body,
                'link' => $link,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'expires_at' => $expiresAt,
            ])->save();

            return $alert;
        });
    }

    /* --------------------------------------------------------- the sweeps */

    /**
     * Everything the scheduler raises, for one branch.
     *
     * @return array<string, int>  what was raised, by kind
     */
    public function sweep(Shop $shop): array
    {
        return [
            'low_stock' => $this->lowStock($shop),
            'day_close_pending' => $this->dayClosePending($shop),
            'table_stuck' => $this->stuckSittings($shop),
        ];
    }

    /**
     * Tills left open after the day they belong to (SRS 15).
     *
     * Only yesterday and earlier. A register open on its own business date is
     * a shop that is trading, not a shop that forgot - flagging that would
     * put an alert on the screen every afternoon and teach everyone to ignore
     * the bell.
     *
     * The reason this matters is that a till counted three days late cannot be
     * reconciled against anything: the cash has been in and out of the drawer
     * since, and the variance stops meaning what it says.
     */
    public function dayClosePending(Shop $shop): int
    {
        // The outlet's day, not the server's. business_date is already a
        // business day so it still compares as a date; what was wrong was
        // which date "today" meant - on a UTC clock this fired five and a
        // half hours after the kitchen had gone home.
        $today = BusinessDay::today($shop);

        $stale = CashRegister::forShop($shop->id)
            ->where('status', CashRegister::OPEN)
            ->whereDate('business_date', '<', $today)
            ->orderBy('business_date')
            ->get();

        foreach ($stale as $register) {
            $days = (int) $register->business_date->diffInDays($today);

            $this->raise(
                shop: $shop,
                type: Alert::DAY_CLOSE_PENDING,
                title: sprintf('%s has not been closed', $register->business_date->format('d M Y')),
                dedupeKey: sprintf('day-close:%d:%s', $register->id, $today->toDateString()),
                body: sprintf(
                    'The till has been open for %d day%s. Cash counted this late cannot be reconciled.',
                    $days,
                    $days === 1 ? '' : 's',
                ),
                link: '/admin/registers/'.$register->id,
                reference: $register,
                can: 'pos.registers.view',
                // A till open for a week is a different problem from one open
                // since yesterday, and should read that way.
                level: $days > 1 ? 'danger' : 'warning',
            );
        }

        return $stale->count();
    }

    /**
     * Sittings nobody ever closed.
     *
     * A table session is opened when a party sits down and closed when they
     * pay and leave. Nothing in the system ends one on its own - deliberately,
     * because a computer cannot know a party has gone home, and one that
     * guessed would close a long dinner mid-meal and lose the bill with it.
     *
     * The cost of that is that a sitting a busy evening forgot simply stays
     * open. Forever: the table keeps reading as occupied on the plan, the
     * kitchen keeps its tickets, the bill is never settled, and the only thing
     * that ever notices is somebody trying to seat the next party there.
     *
     * So nothing closes it, but something says it is there. A day is the
     * threshold rather than a few hours, because a long table booking is a
     * real thing and this must not cry wolf about one.
     */
    public function stuckSittings(Shop $shop, int $afterHours = 24): int
    {
        $stale = TableSession::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->openOrBilled()
            ->where('opened_at', '<', now()->subHours($afterHours))
            ->with('table:id,code,name')
            ->orderBy('opened_at')
            ->get();

        foreach ($stale as $session) {
            $hours = max(1, (int) round($session->seatedMinutes() / 60));

            $this->raise(
                shop: $shop,
                type: Alert::TABLE_STUCK,
                title: sprintf('Table %s has been open for %s',
                    $session->table?->code ?? '?',
                    $hours >= 48 ? round($hours / 24).' days' : $hours.' hours',
                ),
                dedupeKey: sprintf('table-stuck:%d:%s', $session->id, today()->toDateString()),
                body: sprintf(
                    '%s is still seated with the bill open. Nobody can be seated there until '
                    .'it is settled or cleared.',
                    $session->partyName(),
                ),
                link: '/admin/table-bills/'.$session->id,
                reference: $session,
                can: 'pos.tables.view',
                level: $hours >= 48 ? 'danger' : 'warning',
            );
        }

        return $stale->count();
    }

    /* ------------------------------------------------------ event-driven */

    /*
     | The rows of SRS 15's table that are not "check every morning" but
     | "something just happened". They are raised from the service that did
     | the thing, inside the same transaction, so an alert can never claim an
     | invoice was raised that was then rolled back.
     |
     | Each one is addressed by right rather than to a person, for the same
     | reason as the sweeps: the cashier who billed it may be off shift by the
     | time anyone reads the bell.
     */

    /** A bill has been raised (SRS 15). */
    public function invoiceRaised(Invoice $invoice): ?Alert
    {
        $shop = $invoice->shop;

        if (! $shop instanceof Shop) {
            return null;
        }

        return $this->raise(
            shop: $shop,
            type: Alert::INVOICE_GENERATED,
            title: sprintf('%s raised for %s', $invoice->number, number_format((float) $invoice->grand_total, 2)),
            dedupeKey: sprintf('invoice:%d', $invoice->id),
            body: $invoice->customer_name
                ? sprintf('Billed to %s.', $invoice->customer_name)
                : 'Counter sale.',
            link: '/admin/invoices/'.$invoice->id,
            reference: $invoice,
            can: 'sales.invoices.view',
            level: 'info',
            /*
             | Short-lived on purpose. Every bill raising a permanent alert
             | would bury the ones that need acting on within an hour of
             | opening; a day is long enough to be the record of "what went
             | out this morning" and no longer.
             */
            expiresAt: now()->addDay(),
        );
    }

    /**
     * Money has come in (SRS 15).
     *
     * Incoming only. A payment out is the shop settling a supplier, and
     * "Payment received" is not what happened.
     */
    public function paymentReceived(Payment $payment): ?Alert
    {
        $shop = $payment->shop;

        if (! $shop instanceof Shop || $payment->direction !== Payment::IN) {
            return null;
        }

        return $this->raise(
            shop: $shop,
            type: Alert::PAYMENT_RECEIVED,
            title: sprintf('%s received', number_format((float) $payment->amount, 2)),
            dedupeKey: sprintf('payment:%d', $payment->id),
            body: sprintf(
                '%s via %s%s.',
                $payment->party_name ?: 'Walk-in',
                $payment->methodLabel(),
                // Worth saying: a cheque taken today has settled nothing yet.
                $payment->status === Payment::PENDING ? ', not yet cleared' : '',
            ),
            link: '/admin/payments?q='.urlencode((string) $payment->number),
            reference: $payment,
            can: 'finance.payments.view',
            level: 'success',
            expiresAt: now()->addDay(),
        );
    }

    /**
     * Products at or below their reorder level (SRS 15).
     *
     * Deduped on the day rather than on the product's quantity, so a shop that
     * sells three more of something already flagged does not get a second
     * alert about it.
     */
    public function lowStock(Shop $shop): int
    {
        $rows = DB::table('product_stocks')
            ->join('products', 'products.id', '=', 'product_stocks.product_id')
            ->where('product_stocks.shop_id', $shop->id)
            ->whereNull('products.deleted_at')
            // Never a dish: a kitchen cannot run low on Butter Naan, and an
            // alert about one teaches staff to ignore the whole channel.
            ->where('products.is_made_to_order', false)
            ->where('products.reorder_level', '>', 0)
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.reorder_level')
            ->havingRaw('SUM(product_stocks.quantity) <= products.reorder_level')
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'products.reorder_level',
                DB::raw('SUM(product_stocks.quantity) as on_hand'),
            ])
            ->get();

        foreach ($rows as $row) {
            $this->raise(
                shop: $shop,
                type: Alert::LOW_STOCK,
                title: sprintf('%s is low', $row->name),
                dedupeKey: sprintf('low-stock:%d:%s', $row->id, today()->toDateString()),
                body: sprintf(
                    '%s on hand against a reorder level of %s.',
                    rtrim(rtrim(number_format((float) $row->on_hand, 3, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $row->reorder_level, 3, '.', ''), '0'), '.'),
                ),
                link: '/admin/stock?q='.urlencode((string) $row->sku),
                can: 'inventory.stock.view',
                level: 'warning',
                expiresAt: today()->addDays(7)->endOfDay(),
            );
        }

        return $rows->count();
    }

    /* ---------------------------------------------------------- the bell */

    /** Mark one alert seen. */
    public function markRead(Alert $alert): Alert
    {
        if (! $alert->isRead()) {
            $alert->forceFill(['read_at' => now()])->save();
        }

        return $alert;
    }

    /**
     * Mark everything this user can see as read.
     *
     * @return int  how many were marked
     */
    public function markAllRead(User $user): int
    {
        /** @var Collection<int, Alert> $alerts */
        $alerts = Alert::query()
            ->unread()
            ->visibleTo($user)
            ->get()
            ->filter(fn (Alert $alert) => $alert->isVisibleTo($user));

        foreach ($alerts as $alert) {
            $alert->forceFill(['read_at' => now()])->save();
        }

        return $alerts->count();
    }

    /**
     * Delete alerts that have expired.
     *
     * Alerts are transient by nature; one with no expiry stays until it is
     * dealt with, and the rest are swept.
     */
    public function prune(): int
    {
        return Alert::allShops()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
