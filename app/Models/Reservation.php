<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A table promised to somebody for a time (SRS 5, 7, 16, 21).
 *
 * The status is stored, not computed - the opposite of a subscription, and
 * deliberately. Nothing about the clock knows whether a party turned up:
 * "eight o'clock has passed" and "they did not come" are different facts,
 * and only a person at the door knows the second one.
 *
 * @property \Illuminate\Support\Carbon $reserved_for
 * @property int $duration_minutes
 */
class Reservation extends Model
{
    use BelongsToShop;

    /** Taken, not yet promised - an online request waiting to be accepted. */
    public const REQUESTED = 'requested';

    public const CONFIRMED = 'confirmed';

    public const SEATED = 'seated';

    public const COMPLETED = 'completed';

    public const NO_SHOW = 'no_show';

    public const CANCELLED = 'cancelled';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATUSES = [
        self::REQUESTED => ['label' => 'Requested', 'tone' => 'warning'],
        self::CONFIRMED => ['label' => 'Confirmed', 'tone' => 'info'],
        self::SEATED => ['label' => 'Seated', 'tone' => 'success'],
        self::COMPLETED => ['label' => 'Completed', 'tone' => 'muted'],
        self::NO_SHOW => ['label' => 'No show', 'tone' => 'danger'],
        self::CANCELLED => ['label' => 'Cancelled', 'tone' => 'muted'],
    ];

    /**
     * The ones that still hold a table.
     *
     * Everything else has been resolved one way or another and cannot clash
     * with anybody - which is what makes this the right list for the
     * double-booking check.
     *
     * @var array<int, string>
     */
    public const HOLDS_A_TABLE = [self::REQUESTED, self::CONFIRMED, self::SEATED];

    /** @var array<int, string> */
    public const SOURCES = ['phone', 'walk_in', 'online', 'staff'];

    protected $fillable = [
        'shop_id', 'restaurant_table_id', 'customer_id',
        'guest_name', 'guest_mobile', 'guest_email',
        'party_size', 'reserved_for', 'duration_minutes',
        'status', 'source', 'notes', 'outcome_note',
        'table_session_id', 'confirmed_at', 'seated_at', 'closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reserved_for' => 'datetime',
            'party_size' => 'integer',
            'duration_minutes' => 'integer',
            'confirmed_at' => 'datetime',
            'seated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    /** @return BelongsTo<RestaurantTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<TableSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------ scopes */

    /** Bookings that still hold a table, and so can clash. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::HOLDS_A_TABLE);
    }

    public function scopeForDay(Builder $query, Carbon $day): Builder
    {
        return $query->whereBetween('reserved_for', [
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        ]);
    }

    /**
     * Bookings that should be on somebody's screen right now.
     *
     * A window rather than a moment: a booking for eight o'clock matters
     * from about half past seven, when the host starts looking for it, until
     * it is resolved.
     */
    public function scopeUpcoming(Builder $query, int $minutes = 180): Builder
    {
        return $query
            ->live()
            ->where('reserved_for', '<=', now()->addMinutes($minutes))
            ->where('reserved_for', '>=', now()->subHours(4));
    }

    /* --------------------------------------------------------- behaviour */

    public function endsAt(): Carbon
    {
        return $this->reserved_for->copy()->addMinutes($this->duration_minutes);
    }

    public function holdsATable(): bool
    {
        return in_array($this->status, self::HOLDS_A_TABLE, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::NO_SHOW, self::CANCELLED], true);
    }

    /**
     * Past its time and nobody has said what happened.
     *
     * The only clock-derived judgement here, and it is a prompt rather than
     * a state: it puts the booking in front of a host so a person can say
     * whether it was a no-show. It never decides that on their behalf.
     */
    public function isOverdue(int $graceMinutes = 20): bool
    {
        return in_array($this->status, [self::REQUESTED, self::CONFIRMED], true)
            && $this->reserved_for->copy()->addMinutes($graceMinutes)->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status]['label'] ?? ucfirst((string) $this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status]['tone'] ?? 'muted';
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'walk_in' => 'Walk-in',
            'online' => 'Online',
            'staff' => 'Staff',
            default => 'Phone',
        };
    }

    /** "8:00 pm – 9:30 pm", for a list somebody reads at speed. */
    public function windowLabel(): string
    {
        return $this->reserved_for->format('g:i a').' – '.$this->endsAt()->format('g:i a');
    }
}
