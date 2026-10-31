<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sale put to one side (SRS 6).
 *
 * Not a document. Nothing here is numbered, nothing moves stock and nothing
 * reaches a ledger - see the migration. It is a note the till can pick up
 * again, and it is deleted the moment it becomes a real invoice.
 *
 * @property array<string, mixed> $payload
 */
class ParkedSale extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'reference', 'label', 'customer_id', 'channel',
        'payload', 'total', 'line_count', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'total' => 'decimal:2',
            'line_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The next reference for a branch.
     *
     * Deliberately not an invoice number and deliberately not global: these
     * are read aloud across a counter ("get hold fourteen up"), so they want
     * to be small and to restart rather than climb into the thousands.
     */
    public static function nextReference(int $shopId): string
    {
        $last = static::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->max('id');

        return 'HOLD-'.str_pad((string) ((int) $last + 1), 3, '0', STR_PAD_LEFT);
    }

    /** What to call it on the list when nobody typed a label. */
    public function displayLabel(): string
    {
        return $this->label
            ?: ($this->customer?->name ?: $this->reference);
    }

    /**
     * How long it has been sitting there.
     *
     * Shown because a held sale that has been there since lunchtime is
     * either a walk-out or a cashier who forgot, and both are worth somebody
     * noticing before the day is closed.
     */
    public function isStale(int $minutes = 120): bool
    {
        return $this->created_at !== null && $this->created_at->addMinutes($minutes)->isPast();
    }
}
