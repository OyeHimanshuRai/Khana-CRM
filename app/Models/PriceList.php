<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Prices that apply only sometimes (SRS 8, 16).
 *
 * "Half price on beer between four and seven" — the axis the three channel
 * prices cannot express. See the migration.
 *
 * @property array<int, int>|null $weekdays
 */
class PriceList extends Model
{
    use BelongsToShop;
    use SoftDeletes;

    protected $fillable = [
        'shop_id', 'name', 'code',
        'starts_on', 'ends_on', 'starts_at', 'ends_at',
        'weekdays', 'channel', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'weekdays' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /* ------------------------------------------------------------ scopes */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* --------------------------------------------------------- behaviour */

    /**
     * Is this list running at the given moment, on the given channel?
     *
     * Every part of the window is optional and narrows independently, so a
     * list with none of them set is simply always on - which is how a
     * permanent second price list is expressed.
     */
    public function appliesAt(Carbon $at, ?string $channel = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->channel !== null && $channel !== null && $this->channel !== $channel) {
            return false;
        }

        if ($this->starts_on !== null && $at->lt($this->starts_on->startOfDay())) {
            return false;
        }

        if ($this->ends_on !== null && $at->gt($this->ends_on->endOfDay())) {
            return false;
        }

        if ($this->weekdays !== null && ! in_array($at->isoWeekday(), $this->weekdays, true)) {
            return false;
        }

        return $this->withinHours($at);
    }

    /**
     * The time-of-day part, including windows that cross midnight.
     *
     * A bar's late offer runs from 22:00 to 02:00, and the naive comparison
     * - start <= now <= end - is false for every minute of it. Getting this
     * wrong means the one offer that runs at closing time never applies, and
     * nobody notices until a customer argues about a bill at half past one.
     */
    private function withinHours(Carbon $at): bool
    {
        if ($this->starts_at === null || $this->ends_at === null) {
            return true;
        }

        $now = $at->format('H:i:s');
        $from = $this->asTime($this->starts_at);
        $to = $this->asTime($this->ends_at);

        return $from <= $to
            ? ($now >= $from && $now <= $to)
            // Crosses midnight: after the start OR before the end.
            : ($now >= $from || $now <= $to);
    }

    /** Times come back as strings or Carbon depending on the driver. */
    private function asTime(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('H:i:s')
            : substr((string) $value, 0, 8);
    }

    /** The window, in a line somebody can read on a list. */
    public function windowLabel(): string
    {
        $parts = [];

        if ($this->starts_at !== null && $this->ends_at !== null) {
            $parts[] = substr($this->asTime($this->starts_at), 0, 5)
                .'–'.substr($this->asTime($this->ends_at), 0, 5);
        }

        if ($this->weekdays !== null && $this->weekdays !== []) {
            $names = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

            $parts[] = collect($this->weekdays)->map(fn ($d) => $names[$d] ?? '')->implode(', ');
        }

        if ($this->channel !== null) {
            $parts[] = str_replace('_', '-', $this->channel);
        }

        return $parts === [] ? 'Always' : implode(' · ', $parts);
    }
}
