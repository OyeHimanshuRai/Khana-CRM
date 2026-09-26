<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ShopScope;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One in-app notification (SRS 15).
 *
 * Named `alerts` rather than `notifications` because Laravel reserves that
 * table for DatabaseNotification, whose shape - a uuid key and an untyped JSON
 * payload - cannot be filtered by shop, grouped by kind or deduplicated across
 * a scheduler run. All three are things SRS 15 needs. See the migration.
 *
 * Two ways an alert is addressed, and both are real:
 *
 *     user_id set    for one person - "your job order was approved"
 *     `can` set      for whoever holds a permission at that branch, which is
 *                    how SRS 15's own table actually reads: "Low stock ->
 *                    Branch Admin / Warehouse" is a role, not a person, and
 *                    addressing it to whoever happened to be on shift would
 *                    hide it from the person who came in next.
 */
class Alert extends Model
{
    use BelongsToShop;

    /* One key per row of SRS 15's notification table. */
    public const LOW_STOCK = 'low_stock';

    public const PAYMENT_RECEIVED = 'payment_received';

    public const INVOICE_GENERATED = 'invoice_generated';

    public const DAY_CLOSE_PENDING = 'day_close_pending';

    /** A sitting nobody ever closed - see AlertService::stuckSittings(). */
    public const TABLE_STUCK = 'table_stuck';

    public const SECURITY = 'security';

    /** @var array<string, array{label: string, icon: string}> */
    public const TYPES = [
        self::LOW_STOCK => ['label' => 'Low stock', 'icon' => 'package'],
        self::PAYMENT_RECEIVED => ['label' => 'Payment received', 'icon' => 'wallet'],
        self::INVOICE_GENERATED => ['label' => 'Invoice raised', 'icon' => 'file'],
        self::DAY_CLOSE_PENDING => ['label' => 'Day close pending', 'icon' => 'wallet'],
        self::TABLE_STUCK => ['label' => 'Table still open', 'icon' => 'users'],
        self::SECURITY => ['label' => 'Security', 'icon' => 'shield'],
    ];

    /* Written by AlertService alone. */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where('type', $type);
    }

    /**
     * The alerts one person should actually see.
     *
     * Addressed to them, or unaddressed and gated on a permission they hold.
     * The permission check is done in PHP against the user's own rights rather
     * than in SQL, because that is where the answer lives - and the list is
     * small enough that filtering after the fetch costs nothing.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q
                ->where('user_id', $user->getKey())
                ->orWhereNull('user_id'))
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    /* --------------------------------------------------------- behaviour */

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Whether this user is allowed to see it.
     *
     * The half of visibleTo() that cannot be expressed in SQL. Called on the
     * fetched rows; an alert with no `can` is for everybody at the branch.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->user_id !== null && (int) $this->user_id !== (int) $user->getKey()) {
            return false;
        }

        return blank($this->can) || $user->can($this->can);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type]['label'] ?? Str::headline($this->type);
    }

    public function icon(): string
    {
        return self::TYPES[$this->type]['icon'] ?? 'bell';
    }

    /**
     * The unread count for the header bell.
     *
     * Scoped to the branch in context and filtered by what this user may see,
     * so the number always agrees with the list that opens beneath it - a
     * count that does not match what you then find is worse than no count.
     */
    public static function badgeCount(User $user): int
    {
        return static::query()
            ->unread()
            ->visibleTo($user)
            ->get(['id', 'user_id', 'can'])
            ->filter(fn (self $alert) => $alert->isVisibleTo($user))
            ->count();
    }

    /**
     * Recent alerts for the bell, newest first.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function recentFor(User $user, int $limit = 15)
    {
        return static::query()
            ->visibleTo($user)
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (self $alert) => $alert->isVisibleTo($user))
            ->take($limit)
            ->values();
    }

    /**
     * Every branch this user can reach, not only the selected one.
     *
     * The bell is the one place a branch-scoped default is wrong: a manager
     * covering three shops needs to know that one of them is out of stock
     * without having to switch into it to find out.
     */
    public static function acrossShopsFor(User $user, int $limit = 15)
    {
        /*
         | The pivot, not a permission - which shops a person may reach is data
         | (docs/ERP-OVERVIEW section 1). An empty list has to stay empty rather
         | than becoming "no filter", so it falls back to an id nothing matches.
         */
        $shopIds = CurrentShop::accessibleIds() ?: [0];

        return static::withoutGlobalScope(ShopScope::class)
            ->whereIn('shop_id', $shopIds)
            ->visibleTo($user)
            ->with('shop')
            ->orderByDesc('created_at')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (self $alert) => $alert->isVisibleTo($user))
            ->take($limit)
            ->values();
    }
}
