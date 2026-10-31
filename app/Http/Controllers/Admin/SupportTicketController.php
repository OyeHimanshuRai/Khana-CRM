<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Shop;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportTicketService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

/**
 * The support desk (§2).
 *
 * ---------------------------------------------------------------------------
 * One controller, two audiences
 * ---------------------------------------------------------------------------
 *
 * Every screen here serves both the restaurant raising a ticket and the
 * platform staff answering it, and the difference between them is a single
 * permission: `support.tickets.manage`.
 *
 * Two controllers would have been the obvious split and would have been
 * wrong. The thread, the filters, the status badges and the reply box are the
 * same on both sides; only the *scope* of the list and the set of buttons
 * differ. Splitting them would have meant two copies of a conversation view,
 * which is exactly the kind of pair that drifts until an internal note shows
 * up on the customer's screen.
 *
 * So the scope is applied in one place - SupportTicket::scopeVisibleTo() -
 * and the buttons are a `$canManage` flag the views read.
 *
 * ---------------------------------------------------------------------------
 * Route binding is not trusted
 * ---------------------------------------------------------------------------
 *
 * SupportTicket carries no global scope (see the model), so an implicitly
 * bound {ticket} is any ticket on the platform. Every action here re-resolves
 * it through visibleTo() and 404s otherwise - `authorise()` below. A 404
 * rather than a 403 on purpose: telling somebody that ticket TKT-26-00042
 * exists but is not theirs is itself a leak about who else is on the
 * platform.
 */
class SupportTicketController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly SupportTicketService $tickets) {}

    /* ------------------------------------------------------------ reading */

    public function index(Request $request): View
    {
        $canManage = Gate::allows('support.tickets.manage');

        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $query = SupportTicket::query()->visibleTo($request->user());

        $tickets = (clone $query)
            ->with(['tenant:id,name', 'shop:id,name', 'opener:id,name', 'assignee:id,name'])
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->ofPriority($request->string('priority')->toString())
            // Desk-only filter; harmless for anybody else, whose tickets are
            // never assigned to them, so it is not gated.
            ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()?->id))
            /*
             | Newest movement first, not newest ticket. A thread somebody
             | replied to an hour ago is the one that needs reading, however
             | long ago it was opened - and `last_reply_at` is null until
             | somebody does, which is why the raise date is the fallback.
             */
            ->orderByRaw('COALESCE(last_reply_at, created_at) DESC')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'tickets' => $tickets,
            'canManage' => $canManage,
            'stats' => $this->tickets->stats(clone $query),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'priority' => $request->string('priority')->toString(),
            'mine' => $request->boolean('mine'),
            'statuses' => SupportTicket::STATUSES,
            'priorities' => SupportTicket::PRIORITIES,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
        ];

        return $request->header('X-Fragment')
            ? view('admin.support._list', $data)
            : view('admin.support.index', $data);
    }

    public function show(Request $request, SupportTicket $ticket): View
    {
        $this->authorise($ticket, $request);

        $canManage = Gate::allows('support.tickets.manage');

        /*
         | The internal-note filter is the whole security boundary of this
         | screen, and it is a relation rather than a where() here so it cannot
         | be forgotten - see SupportTicket::visibleReplies().
         */
        $ticket->load([
            'tenant:id,name',
            'shop:id,name',
            'opener:id,name',
            'assignee:id,name',
            'closer:id,name',
            ($canManage ? 'replies' : 'visibleReplies').'.author:id,name',
        ]);

        return view('admin.support.show', [
            'ticket' => $ticket,
            'replies' => $canManage ? $ticket->replies : $ticket->visibleReplies,
            'canManage' => $canManage,
            'agents' => $canManage ? $this->agents() : collect(),
            'statuses' => SupportTicket::STATUSES,
            'priorities' => SupportTicket::PRIORITIES,
        ]);
    }

    /**
     * The "raise a ticket" form, rendered straight into the modal body - the
     * same shape every other create form on this panel uses.
     */
    public function create(): View
    {
        return view('admin.support._form', [
            'categories' => SupportTicket::CATEGORIES,
            'priorities' => SupportTicket::PRIORITIES,
            'shops' => $this->selectableShops(),
        ]);
    }

    /* ------------------------------------------------------------ writing */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:10000'],
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(SupportTicket::CATEGORIES))],
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(SupportTicket::PRIORITIES))],
            'shop_id' => ['nullable', 'integer'],
        ]);

        /*
         | The company is taken from the actor's context, never from the
         | request. A tenant_id in the payload would let anybody file a ticket
         | against a competitor - and because the desk answers whatever company
         | the ticket names, that is a way to read somebody else's reply.
         */
        $tenantId = CurrentTenant::id() ?? $request->user()?->tenant_id;

        if ($tenantId === null) {
            return ApiResponse::error('Pick a company before raising a ticket.');
        }

        // Same rule one tier down: the branch must be one the actor can reach.
        $shopId = $data['shop_id'] ?? null;

        if ($shopId !== null && ! $this->selectableShops()->contains('id', (int) $shopId)) {
            return ApiResponse::error('That branch is not yours to file against.');
        }

        $ticket = $this->tickets->open([
            ...$data,
            'tenant_id' => $tenantId,
            'shop_id' => $shopId,
        ], $request->user());

        return ApiResponse::success(
            "Ticket {$ticket->reference} raised.",
            redirect: route('admin.support.show', $ticket),
        );
    }

    /**
     * Post a message.
     *
     * `from_staff` and `internal` are both decided here from the actor's
     * rights rather than taken from the form. A restaurant posting
     * `internal=1` would otherwise be able to write a note the desk reads as
     * its own, and one posting `from_staff=1` could forge an answer into their
     * own thread - neither is a hypothetical, both are one curl away.
     */
    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorise($ticket, $request);

        $canManage = Gate::allows('support.tickets.manage');

        /*
         | Reading a thread is not permission to write in it.
         |
         | The route asks only for `view`, because that is what decides
         | *reachability* - and `view` is deliberately wide: the Auditor role
         | is read-only across the whole system and holds it. Without this
         | check that role could post replies, which is precisely what
         | "read-only" is supposed to rule out.
         |
         | So writing needs `create` (the restaurant's own side - the same
         | right that raises a ticket) or `manage` (the desk). `edit` is not
         | accepted: it is the right to resolve and close, which a manager may
         | hold without being the person who talks to the platform.
         */
        abort_unless($canManage || Gate::allows('support.tickets.create'), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'internal' => ['sometimes', 'boolean'],
        ]);

        if (! $ticket->acceptsReplies()) {
            return ApiResponse::error('This ticket is closed. Reopen it to carry on the conversation.');
        }

        $internal = $canManage && $request->boolean('internal');

        $this->tickets->reply(
            $ticket,
            $data['body'],
            $request->user(),
            fromStaff: $canManage,
            internal: $internal,
        );

        return ApiResponse::success(
            $internal ? 'Note added.' : 'Reply sent.',
            redirect: route('admin.support.show', $ticket),
        );
    }

    public function resolve(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorise($ticket, $request);

        $this->tickets->resolve($ticket);

        return ApiResponse::success(
            "Ticket {$ticket->reference} marked resolved.",
            redirect: route('admin.support.show', $ticket),
        );
    }

    public function close(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorise($ticket, $request);

        $this->tickets->close($ticket, $request->user());

        return ApiResponse::success(
            "Ticket {$ticket->reference} closed.",
            redirect: route('admin.support.show', $ticket),
        );
    }

    public function reopen(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorise($ticket, $request);

        $this->tickets->reopen($ticket);

        return ApiResponse::success(
            "Ticket {$ticket->reference} reopened.",
            redirect: route('admin.support.show', $ticket),
        );
    }

    /**
     * Hand a ticket to somebody on the desk.
     *
     * Desk-only, and the assignee has to hold `manage` too: assigning a ticket
     * to a restaurant's own manager would put a row in their queue they cannot
     * open, which is a confusing way to lose a support request.
     */
    public function assign(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorise($ticket, $request);
        abort_unless(Gate::allows('support.tickets.manage'), 403);

        $data = $request->validate([
            'assigned_to' => ['nullable', 'integer'],
        ]);

        $assignee = null;

        if (! empty($data['assigned_to'])) {
            $assignee = $this->agents()->firstWhere('id', (int) $data['assigned_to']);

            if ($assignee === null) {
                return ApiResponse::error('That person does not work the support desk.');
            }
        }

        $this->tickets->assign($ticket, $assignee);

        return ApiResponse::success(
            $assignee ? "Assigned to {$assignee->name}." : 'Returned to the pool.',
            redirect: route('admin.support.show', $ticket),
        );
    }

    public function priority(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorise($ticket, $request);
        abort_unless(Gate::allows('support.tickets.manage'), 403);

        $data = $request->validate([
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(SupportTicket::PRIORITIES))],
        ]);

        $this->tickets->setPriority($ticket, $data['priority']);

        return ApiResponse::success(
            'Priority updated.',
            redirect: route('admin.support.show', $ticket),
        );
    }

    public function destroy(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorise($ticket, $request);

        $reference = $ticket->reference;
        $ticket->delete();

        ActivityLog::record('support.deleted', "Deleted support ticket {$reference}", $ticket);

        return ApiResponse::success('Ticket removed.');
    }

    /* ---------------------------------------------------------- exporting */

    /**
     * The queue as a spreadsheet.
     *
     * Bodies are left out deliberately. A support thread is the one place on
     * this platform where a customer routinely pastes a card number or a
     * password, and a CSV of every message is the easiest way for that to end
     * up in a shared drive. The columns here are the ones somebody exports a
     * queue to count.
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = SupportTicket::query()
            ->visibleTo($request->user())
            ->with(['tenant:id,name', 'shop:id,name', 'assignee:id,name'])
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->ofPriority($request->string('priority')->toString())
            ->latest('id')
            /*
             | lazy(), not cursor(): cursor() streams one row at a time and
             | resolves the eager loads per row, which is a query per ticket on
             | the one screen guaranteed to be reading thousands of them.
             | lazy() keeps the streaming and batches the relations.
             */
            ->lazy(500);

        $name = 'support-tickets-'.now()->format('Y-m-d').'.csv';

        return Response::streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Reference', 'Raised', 'Company', 'Branch', 'Subject', 'Category',
                'Priority', 'Status', 'Assigned to', 'First response (min)',
                'Last activity', 'Resolved', 'Closed',
            ]);

            foreach ($rows as $t) {
                fputcsv($out, [
                    $t->reference,
                    $t->created_at?->format('Y-m-d H:i'),
                    $t->tenant?->name,
                    $t->shop?->name,
                    $t->subject,
                    $t->categoryLabel(),
                    $t->priorityLabel(),
                    $t->statusLabel(),
                    $t->assignee?->name,
                    $t->firstResponseMinutes(),
                    $t->lastMovedAt()->format('Y-m-d H:i'),
                    $t->resolved_at?->format('Y-m-d H:i'),
                    $t->closed_at?->format('Y-m-d H:i'),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /* ----------------------------------------------------------- internals */

    /**
     * 404 a ticket this user may not read. See the class docblock for why it
     * is a 404 and not a 403.
     */
    private function authorise(SupportTicket $ticket, Request $request): void
    {
        $visible = SupportTicket::query()
            ->visibleTo($request->user())
            ->whereKey($ticket->getKey())
            ->exists();

        abort_unless($visible, 404);
    }

    /**
     * The people who may be given a ticket.
     *
     * Read straight off the permission rather than off a role name, so a
     * platform that renames "Support Agent" tomorrow does not quietly empty
     * this list.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function agents(): \Illuminate\Support\Collection
    {
        /*
         | Filtered in PHP rather than by a join, because the right can arrive
         | either directly or through a role and Spatie resolves both. The
         | eager loads are what keep that from being a query per user - without
         | them `can()` lazy-loads roles and permissions for every row.
         |
         | Cached for the request: show() calls this, and so does assign() on
         | the same page load.
         */
        return once(fn () => User::query()
            ->select('id', 'name')
            ->with(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name'])
            ->get()
            ->filter(fn (User $u) => $u->can('support.tickets.manage'))
            ->values());
    }

    /**
     * Branches this actor may file a ticket against.
     *
     * `CurrentShop::accessible()`, and emphatically not `Shop::query()`.
     *
     * Shop is one of the few models here that carries NO global scope - it is
     * the thing the scope is defined in terms of, so it cannot scope itself.
     * A plain query therefore returns every branch on the platform, and using
     * one here would have put every other restaurant's branch names in this
     * dropdown and let somebody file a ticket against one.
     *
     * The same list is what store() validates the submitted shop_id against,
     * so the check and the options can never disagree.
     *
     * @return \Illuminate\Support\Collection<int, Shop>
     */
    private function selectableShops(): \Illuminate\Support\Collection
    {
        return once(fn () => CurrentShop::accessible()->sortBy('name')->values());
    }
}
