<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A table people sit at.
 *
 * See the migration for why this is `restaurant_tables` rather than the
 * requirements' `tables`.
 */
class RestaurantTable extends Model
{
    use BelongsToShop;

    public const AVAILABLE = 'available';

    public const OCCUPIED = 'occupied';

    public const RESERVED = 'reserved';

    public const BILLING = 'billing';

    public const CLEANING = 'cleaning';

    /**
     * The five states §5 asks the dashboard to count, with the tone each one
     * renders in so the floor plan and the dashboard cannot disagree about
     * what colour "billing" is.
     *
     * @var array<string, array{label: string, tone: string}>
     */
    public const STATUSES = [
        self::AVAILABLE => ['label' => 'Available', 'tone' => 'success'],
        self::OCCUPIED => ['label' => 'Occupied', 'tone' => 'warning'],
        self::RESERVED => ['label' => 'Reserved', 'tone' => 'info'],
        self::BILLING => ['label' => 'Billing', 'tone' => 'brand'],
        // No tone: a table being wiped down is not a state anybody needs
        // colour to find, and a fifth colour on the plan is four too many.
        self::CLEANING => ['label' => 'Cleaning', 'tone' => ''],
    ];

    protected $fillable = [
        'shop_id', 'floor_id', 'name', 'code', 'capacity',
        'status', 'note', 'pos_x', 'pos_y', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'pos_x' => 'decimal:3',
            'pos_y' => 'decimal:3',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** Every code this table has ever carried, newest first. */
    public function qrs(): HasMany
    {
        return $this->hasMany(TableQr::class)->orderByDesc('id');
    }

    /**
     * The code currently on the table, if it has one.
     *
     * `latestOfMany` rather than `hasOne` on the bare condition, so eager
     * loading a floor plan of sixty tables is one extra query rather than
     * sixty.
     */
    public function activeQr(): HasOne
    {
        return $this->hasOne(TableQr::class)
            ->whereNull('revoked_at')
            ->latestOfMany();
    }

    /**
     * The party sitting here now, if any.
     *
     * Open or billed, not merely open: a table whose bill is being settled
     * still has people at it, and the floor plan has to say so.
     *
     * latestOfMany so a plan of sixty tables is one extra query rather than
     * sixty - the same reason activeQr is shaped this way.
     */
    public function currentSession(): HasOne
    {
        return $this->hasOne(TableSession::class)
            ->whereIn('status', [TableSession::OPEN, TableSession::BILLED])
            ->latestOfMany();
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('code', 'like', "%{$term}%")
            ->orWhere('note', 'like', "%{$term}%")
            ->orWhereHas('floor', fn (Builder $f) => $f->where('name', 'like', "%{$term}%")));
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * What the floor should actually say about this table.
     *
     * `status` is a flag a member of staff sets. A sitting is a bill with
     * people attached to it. When the two disagree, the bill wins - and they
     * do drift apart, because a status can be changed from the plan at any
     * time while the sitting underneath it carries on.
     *
     * The floor has read one thing and Table Bills the other: a table showing
     * "Cleaning" on the plan while a party sat at it with an open bill and
     * money owed. Whichever screen a member of staff happened to open decided
     * whether that table was free, and one of the two answers seats a second
     * party on top of the first.
     *
     * Only ever promoted, never demoted. A table somebody marked occupied for
     * a walk-in who has not scanned anything has no sitting yet and is still
     * genuinely occupied; clearing that would be this method inventing a free
     * table rather than reporting one.
     */
    public function effectiveStatus(): string
    {
        $session = $this->currentSession;

        if ($session === null) {
            return (string) $this->status;
        }

        return $session->status === TableSession::BILLED ? self::BILLING : self::OCCUPIED;
    }

    public function statusLabel(): string
    {
        $status = $this->effectiveStatus();

        return self::STATUSES[$status]['label'] ?? ucfirst($status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->effectiveStatus()]['tone'] ?? '';
    }

    /** "Rooftop / 12", for a KOT header or a bill line. */
    public function fullName(): string
    {
        return trim(($this->floor?->name ?? '').' / '.$this->name, ' /');
    }

    /**
     * Whether somebody is sitting here as far as the system knows.
     *
     * Billing counts as seated: the guests have not left, and offering the
     * table to the next party while the bill is being settled is how two
     * groups end up at one table.
     */
    public function isSeated(): bool
    {
        return in_array($this->effectiveStatus(), [self::OCCUPIED, self::BILLING], true);
    }

    /**
     * The next code for a new table on this floor.
     *
     * Floor code plus a zero-padded number, counted across the *branch*
     * rather than the floor, because §7's codes are printed on a KOT where
     * two floors' "GF-01" and "RT-01" have to stay apart at a glance but
     * nothing renumbers when a table moves between them.
     */
    public static function nextCode(Floor $floor): string
    {
        $prefix = strtoupper($floor->code).'-';

        $last = static::query()
            ->where('shop_id', $floor->shop_id)
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');

        $next = $last === null
            ? 1
            : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
    }
}
