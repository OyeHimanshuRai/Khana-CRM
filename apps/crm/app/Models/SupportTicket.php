<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One conversation between a restaurant and the platform (§2).
 *
 * ---------------------------------------------------------------------------
 * Why there is no BelongsToShop
 * ---------------------------------------------------------------------------
 *
 * Almost every other model here carries it, and the absence is deliberate: a
 * ticket belongs to the company, and its `shop_id` is a detail *about* it
 * rather than the thing that owns it (see the migration). Applying the trait
 * would narrow the list to the branch the user happens to be switched to,
 * which for a support desk is exactly wrong - an owner who opened a ticket
 * about the rooftop branch must still find it from the ground floor.
 *
 * Nor does it use BelongsToTenant. A global tenant scope would make the
 * platform's own queue invisible to the people who work it, and the escape
 * hatch would then be on the hot path rather than the cold one. Scoping here
 * is explicit and lives in one place: scopeVisibleTo().
 *
 * @property int $id
 * @property string $reference
 * @property string $status
 * @property string $priority
 * @property \Illuminate\Support\Carbon|null $last_reply_at
 * @property \Illuminate\Support\Carbon|null $first_responded_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SupportTicketReply> $replies
 */
class SupportTicket extends Model
{
    /* ------------------------------------------------------------ status */

    /** Raised, nobody has answered yet. */
    public const OPEN = 'open';

    /** The desk has replied and is waiting on the restaurant. */
    public const AWAITING_CUSTOMER = 'awaiting_customer';

    /** The restaurant has replied and is waiting on the desk. */
    public const AWAITING_SUPPORT = 'awaiting_support';

    /** Answered. Still re-openable by a reply - see reopen(). */
    public const RESOLVED = 'resolved';

    /** Done, and a reply will not bring it back. */
    public const CLOSED = 'closed';

    public const STATUSES = [
        self::OPEN => 'Open',
        self::AWAITING_SUPPORT => 'Awaiting support',
        self::AWAITING_CUSTOMER => 'Awaiting customer',
        self::RESOLVED => 'Resolved',
        self::CLOSED => 'Closed',
    ];

    /**
     * The states the desk still owes an answer on.
     *
     * Resolved is not here on purpose: it is finished work that has not been
     * filed away yet, and counting it as open makes the queue look permanently
     * behind.
     */
    public const ACTIVE_STATUSES = [self::OPEN, self::AWAITING_SUPPORT, self::AWAITING_CUSTOMER];

    /* ---------------------------------------------------------- priority */

    public const LOW = 'low';

    public const NORMAL = 'normal';

    public const HIGH = 'high';

    /** The restaurant cannot trade. Everything else waits. */
    public const URGENT = 'urgent';

    public const PRIORITIES = [
        self::LOW => 'Low',
        self::NORMAL => 'Normal',
        self::HIGH => 'High',
        self::URGENT => 'Urgent',
    ];

    /* ---------------------------------------------------------- category */

    /**
     * Deliberately short, and named for what a restaurant would call it
     * rather than for the module it maps to. Somebody whose card machine is
     * refusing does not think of it as "finance.online_payments".
     */
    public const CATEGORIES = [
        'billing' => 'Billing & subscription',
        'payments' => 'Payments & settlement',
        'hardware' => 'Printers & devices',
        'menu' => 'Menu & pricing',
        'orders' => 'Orders & KOT',
        'account' => 'Users & access',
        'bug' => 'Something is broken',
        'other' => 'Something else',
    ];

    protected $fillable = [
        'tenant_id', 'shop_id', 'reference', 'subject', 'body',
        'category', 'priority', 'status',
        'opened_by', 'assigned_to',
        'last_reply_at', 'first_responded_at',
        'resolved_at', 'closed_at', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The branch it is about, where it is about one.
     *
     * `withoutGlobalScopes` because Shop carries its own scoping and a ticket
     * about the rooftop must still name the rooftop when the reader is
     * switched to the ground floor. Reading the name of a branch inside a
     * ticket the reader is already allowed to see leaks nothing.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<SupportTicketReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class)->orderBy('created_at');
    }

    /**
     * The thread as the restaurant sees it.
     *
     * The internal-note filter lives here, once, rather than at each of the
     * three call sites that render a thread - a note leaking is the one bug
     * in this module that would actually cost somebody an account.
     *
     * @return HasMany<SupportTicketReply, $this>
     */
    public function visibleReplies(): HasMany
    {
        return $this->replies()->where('internal', false);
    }

    /* ------------------------------------------------------------ scopes */

    /**
     * Narrow a query to what this user may see.
     *
     * Two audiences, one screen:
     *
     *   the desk         everything, because that is the job
     *   a restaurant     their own company's tickets, and nothing else
     *
     * `support.tickets.manage`-holders are the desk. Everybody else is
     * narrowed to the companies they can reach, which for a Tenant Owner is
     * their own and for a branch manager is the same one. An empty
     * accessible-id list becomes `[0]` rather than no filter at all, so the
     * failure mode is an empty page and never the whole platform's.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->can('support.tickets.manage')) {
            return $query;
        }

        return $query->whereIn('tenant_id', \App\Support\CurrentTenant::accessibleIds() ?: [0]);
    }

    /** Still needing somebody to do something. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /** The desk's own queue: what is waiting on us, worst first. */
    public function scopeAwaitingSupport(Builder $query): Builder
    {
        return $query->whereIn('status', [self::OPEN, self::AWAITING_SUPPORT]);
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return $status && isset(self::STATUSES[$status])
            ? $query->where('status', $status)
            : $query;
    }

    public function scopeOfPriority(Builder $query, ?string $priority): Builder
    {
        return $priority && isset(self::PRIORITIES[$priority])
            ? $query->where('priority', $priority)
            : $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('reference', 'like', "%{$term}%")
                ->orWhere('subject', 'like', "%{$term}%")
                ->orWhere('body', 'like', "%{$term}%");
        });
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * The next unused reference.
     *
     * Year-prefixed and zero-padded within the year, so a number read out on
     * the phone says roughly when it was raised and sorts correctly in a
     * spreadsheet. The max is taken over the current year's prefix only, which
     * keeps the sequence short and restarts it each January.
     *
     * Racy by construction under concurrent inserts; the unique index on
     * `reference` is what actually guarantees it, and the caller retries. At
     * support-desk volume that retry will effectively never run.
     */
    public static function nextReference(): string
    {
        $prefix = 'TKT-'.now()->format('y').'-';

        $last = static::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /**
     * May this ticket still take a reply?
     *
     * Resolved can: a restaurant saying "that did not fix it" is the single
     * most useful message a support desk gets, and a form that refused it
     * would turn it into a second ticket with none of the context. Closed
     * cannot - that is the difference between the two states.
     */
    public function acceptsReplies(): bool
    {
        return ! $this->isClosed();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Something else';
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::OPEN, self::AWAITING_SUPPORT => 'warning',
            self::AWAITING_CUSTOMER => 'info',
            self::RESOLVED => 'success',
            default => 'default',
        };
    }

    public function priorityTone(): string
    {
        return match ($this->priority) {
            self::URGENT => 'danger',
            self::HIGH => 'warning',
            self::LOW => 'default',
            default => 'info',
        };
    }

    /**
     * How long the restaurant waited for a first answer, in minutes.
     *
     * Null while nobody has answered - deliberately not "the time so far",
     * because a report averaging answered and unanswered tickets together
     * would flatter the desk every time it ignored one.
     */
    public function firstResponseMinutes(): ?int
    {
        return $this->first_responded_at
            ? $this->created_at->diffInMinutes($this->first_responded_at)
            : null;
    }

    /** When something last happened, whichever end it came from. */
    public function lastMovedAt(): Carbon
    {
        return $this->last_reply_at ?? $this->created_at;
    }
}
