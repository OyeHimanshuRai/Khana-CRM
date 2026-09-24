<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The rules of the support desk (§2).
 *
 * ---------------------------------------------------------------------------
 * Why a service and not four lines in the controller
 * ---------------------------------------------------------------------------
 *
 * Because the status is the product here. A ticket's state is the answer to
 * "whose turn is it", and that answer moves on every reply, from both ends,
 * in opposite directions. Spread across a controller it would be four
 * near-identical branches that drift apart the first time somebody adds a
 * screen - and the symptom of the drift is a restaurant waiting three days
 * because their reply left the ticket looking answered.
 *
 * So: replies go through reply(). Nothing else writes `status`,
 * `last_reply_at` or `first_responded_at`.
 */
class SupportTicketService
{
    /**
     * Raise a ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function open(array $data, User $actor): SupportTicket
    {
        $ticket = $this->createWithReference([
            'tenant_id' => $data['tenant_id'],
            'shop_id' => $data['shop_id'] ?? null,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'category' => $data['category'] ?? 'other',
            'priority' => $data['priority'] ?? SupportTicket::NORMAL,
            'status' => SupportTicket::OPEN,
            'opened_by' => $actor->id,
        ]);

        ActivityLog::record(
            'support.opened',
            sprintf('Raised support ticket %s: %s', $ticket->reference, $ticket->subject),
            $ticket,
        );

        return $ticket;
    }

    /**
     * Insert, retrying once on a reference collision.
     *
     * SupportTicket::nextReference() reads the current maximum and adds one,
     * which two simultaneous inserts can both win. The unique index is what
     * actually prevents the duplicate; this is what turns that into a working
     * insert rather than a 500 on somebody's support request. One retry is
     * enough for a desk that will never see two tickets in the same
     * millisecond, and a second collision is a real fault worth surfacing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createWithReference(array $attributes): SupportTicket
    {
        try {
            return SupportTicket::create([...$attributes, 'reference' => SupportTicket::nextReference()]);
        } catch (QueryException $e) {
            if (! $this->isDuplicate($e)) {
                throw $e;
            }

            return SupportTicket::create([...$attributes, 'reference' => SupportTicket::nextReference()]);
        }
    }

    private function isDuplicate(QueryException $e): bool
    {
        // 23000 is the SQLSTATE class for an integrity constraint violation,
        // which on this table can only be the unique reference.
        return ($e->errorInfo[0] ?? null) === '23000';
    }

    /**
     * Add a message to a thread, and move the ticket to match.
     *
     * The status change is the point of this method, and it depends on which
     * side is speaking:
     *
     *   the restaurant   the desk now owes an answer   -> awaiting_support
     *   the desk         the restaurant does           -> awaiting_customer
     *
     * A reply from the restaurant to a *resolved* ticket reopens it. That is
     * the case the resolved state exists to catch - "that did not work" has to
     * land back in the queue, not sit unread under a green tick.
     *
     * An internal note moves nothing. It is the desk talking to itself, and a
     * note that flipped the ticket to "awaiting customer" would tell the
     * restaurant they had been answered when they had not.
     */
    public function reply(
        SupportTicket $ticket,
        string $body,
        User $author,
        bool $fromStaff,
        bool $internal = false,
    ): SupportTicketReply {
        return DB::transaction(function () use ($ticket, $body, $author, $fromStaff, $internal) {
            $reply = $ticket->replies()->create([
                'user_id' => $author->id,
                // Frozen at write time - see the migration.
                'author_name' => $author->name,
                'from_staff' => $fromStaff,
                'internal' => $internal,
                'body' => $body,
            ]);

            if ($internal) {
                ActivityLog::record(
                    'support.noted',
                    sprintf('Internal note on %s', $ticket->reference),
                    $ticket,
                );

                return $reply;
            }

            $ticket->last_reply_at = $reply->created_at;

            if ($fromStaff) {
                // The first answer, and only ever set once. See the migration.
                $ticket->first_responded_at ??= $reply->created_at;
                $ticket->status = SupportTicket::AWAITING_CUSTOMER;
            } else {
                $ticket->status = SupportTicket::AWAITING_SUPPORT;

                // Reopening: the ticket is live again, so the outcome stamps
                // have to go or a closed-ticket report will double-count it.
                $ticket->resolved_at = null;
            }

            $ticket->save();

            ActivityLog::record(
                'support.replied',
                sprintf('Replied to support ticket %s', $ticket->reference),
                $ticket,
            );

            return $reply;
        });
    }

    /**
     * Mark a ticket answered.
     *
     * Deliberately not the same thing as closing it - see the model. Resolved
     * means "we think this is done"; the restaurant still gets the last word,
     * and a reply brings it straight back.
     */
    public function resolve(SupportTicket $ticket): SupportTicket
    {
        $ticket->forceFill([
            'status' => SupportTicket::RESOLVED,
            'resolved_at' => now(),
            // A desk that resolves without ever replying still answered it -
            // usually on the phone - and the response-time report should say
            // so rather than leave the ticket looking ignored.
            'first_responded_at' => $ticket->first_responded_at ?? now(),
        ])->save();

        ActivityLog::record(
            'support.resolved',
            sprintf('Resolved support ticket %s', $ticket->reference),
            $ticket,
        );

        return $ticket;
    }

    /** File it away. A reply will not reopen a closed ticket. */
    public function close(SupportTicket $ticket, User $actor): SupportTicket
    {
        $ticket->forceFill([
            'status' => SupportTicket::CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'resolved_at' => $ticket->resolved_at ?? now(),
        ])->save();

        ActivityLog::record(
            'support.closed',
            sprintf('Closed support ticket %s', $ticket->reference),
            $ticket,
        );

        return $ticket;
    }

    /**
     * Put a closed ticket back in the queue.
     *
     * Lands on awaiting_support rather than open: it is not new work, it is
     * work that was called finished and was not, which is the more urgent of
     * the two and should not drop to the bottom of a list sorted by age.
     */
    public function reopen(SupportTicket $ticket): SupportTicket
    {
        $ticket->forceFill([
            'status' => SupportTicket::AWAITING_SUPPORT,
            'resolved_at' => null,
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        ActivityLog::record(
            'support.reopened',
            sprintf('Reopened support ticket %s', $ticket->reference),
            $ticket,
        );

        return $ticket;
    }

    /** Hand a ticket to somebody on the desk, or put it back in the pool. */
    public function assign(SupportTicket $ticket, ?User $assignee): SupportTicket
    {
        $ticket->forceFill(['assigned_to' => $assignee?->id])->save();

        ActivityLog::record(
            'support.assigned',
            $assignee
                ? sprintf('Assigned support ticket %s to %s', $ticket->reference, $assignee->name)
                : sprintf('Unassigned support ticket %s', $ticket->reference),
            $ticket,
        );

        return $ticket;
    }

    public function setPriority(SupportTicket $ticket, string $priority): SupportTicket
    {
        $ticket->forceFill(['priority' => $priority])->save();

        ActivityLog::record(
            'support.prioritised',
            sprintf('Set %s to %s priority', $ticket->reference, $ticket->priorityLabel()),
            $ticket,
        );

        return $ticket;
    }

    /**
     * The numbers on top of the desk's list.
     *
     * Counted over the visible set rather than the whole table, so a Tenant
     * Owner's "3 open" means three of theirs. `waiting` is the only one that
     * is a to-do list: it excludes awaiting_customer, because a ticket sitting
     * with the restaurant is not the desk being behind.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<SupportTicket>  $visible
     * @return array<string, int|float|null>
     */
    public function stats(\Illuminate\Database\Eloquent\Builder $visible): array
    {
        $open = (clone $visible)->active()->count();
        $waiting = (clone $visible)->awaitingSupport()->count();
        $urgent = (clone $visible)->active()->where('priority', SupportTicket::URGENT)->count();

        /*
         | Median would be the better statistic and the mean is the one people
         | can check against a spreadsheet. Over answered tickets only - see
         | SupportTicket::firstResponseMinutes() for why the unanswered are
         | left out rather than counted as "so far".
         */
        $answered = (clone $visible)
            ->whereNotNull('first_responded_at')
            ->where('created_at', '>=', now()->subDays(30));

        $avg = $answered->count() > 0
            ? (clone $answered)->avg(DB::raw($this->seconds('created_at', 'first_responded_at')))
            : null;

        return [
            'open' => $open,
            'waiting' => $waiting,
            'urgent' => $urgent,
            'response_minutes' => $avg !== null ? (int) round(((float) $avg) / 60) : null,
        ];
    }

    /**
     * Seconds between two datetime columns, in this connection's dialect.
     *
     * The same problem, and the same answer, as ReportService::seconds() - see
     * its docblock for why subtracting two DATETIMEs directly is not portable
     * and, on MySQL, not even a duration. Duplicated rather than shared
     * because the two classes have no other reason to know about each other,
     * and a four-line dialect table is a cheaper thing to have twice than a
     * base class is to have at all.
     *
     * The inputs are column names this class supplies, never request data.
     */
    private function seconds(string $from, string $to): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "(strftime('%s', {$to}) - strftime('%s', {$from}))",
            'pgsql' => "EXTRACT(EPOCH FROM ({$to} - {$from}))",
            default => "TIMESTAMPDIFF(SECOND, {$from}, {$to})",
        };
    }
}
