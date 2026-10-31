<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * An order: a table's, a counter's or the web's - but never the bill.
 *
 * One table for every channel (§13, §6) rather than a `table_orders` beside
 * it; see the migration that widened it for why. `order_type` says where it
 * came from, and two columns are nullable because of dine-in: a guest need
 * not sign in, and a table pays at the end rather than when it orders.
 *
 * The rest of this note is about the storefront half and is still exactly
 * true of it.
 *
 * Kept separate from Invoice on purpose: InvoiceService refuses a
 * part-paid/credit sale to a customer who isn't credit-eligible (see its
 * settleCredit()), and a storefront customer normally has allow_credit =
 * false. A COD order placed with nothing collected yet therefore has
 * nowhere to live as an Invoice until the shop actually takes the money -
 * this row is that place, with its own fulfilment `status` distinct from an
 * Invoice's billing status. See OrderService for the full lifecycle.
 *
 * Carries BelongsToShop for free admin-side scoping under staff sessions.
 * That scope is a no-op under the `customer` guard storefront requests run
 * on (Auth::hasUser() only ever looks at the default `web` guard) - every
 * storefront read of this model must go through ::forShop() explicitly.
 */
class Order extends Model
{
    use BelongsToShop;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const PACKING = 'packing';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    /*
     | The kitchen's words for the middle of the ladder (§9).
     |
     | `preparing` and `ready` are new; `pending` and `confirmed` are the same
     | stages the web flow already called by those names, and `served` is
     | dine-in's `delivered`. Existing rows keep their spellings - rewriting
     | them would break every report already run and filed - so the map below
     | simply knows both.
     */
    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const SERVED = 'served';

    /** @var array<string, string> */
    public const STATUSES = [
        self::PENDING => 'Placed',
        self::CONFIRMED => 'Accepted',
        self::PREPARING => 'Preparing',
        self::PACKING => 'Packing',
        self::READY => 'Ready',
        self::SHIPPED => 'Out for delivery',
        self::SERVED => 'Served',
        self::DELIVERED => 'Delivered',
        self::CANCELLED => 'Cancelled',
    ];

    /*
     | Where the order came from (§6).
     |
     | `online` is the default and is what every row written before the
     | restaurant scope existed means.
     */
    public const DINE_IN = 'dine_in';

    public const TAKEAWAY = 'takeaway';

    public const DELIVERY = 'delivery';

    public const WEB = 'online';

    /** @var array<string, string> */
    public const TYPES = [
        self::DINE_IN => 'Dine-in',
        self::TAKEAWAY => 'Takeaway',
        self::DELIVERY => 'Delivery',
        self::WEB => 'Online',
    ];

    /**
     * The ladder a kitchen ticket climbs, in order (§9).
     *
     * Not the web's - a dine-in order is never packed or shipped - so the KDS
     * reads this rather than STATUSES, which is a vocabulary rather than a
     * sequence.
     *
     * @var array<int, string>
     */
    public const KITCHEN_FLOW = [
        self::PENDING,
        self::CONFIRMED,
        self::PREPARING,
        self::READY,
        self::SERVED,
    ];

    public const COD = 'cod';

    public const ONLINE = 'online';

    public const PAYMENT_PENDING = 'pending';

    public const PAYMENT_PAID = 'paid';

    public const PAYMENT_FAILED = 'failed';

    public const PAYMENT_REFUNDED = 'refunded';

    protected $fillable = [
        'shop_id', 'customer_id', 'warehouse_id',
        'table_session_id', 'order_type', 'guest_name', 'guest_mobile',
        'order_number', 'status', 'payment_method', 'payment_status',
        'subtotal', 'discount_total', 'coupon_id', 'coupon_code',
        'shipping_amount', 'grand_total',
        'ship_recipient_name', 'ship_mobile',
        'ship_address_line1', 'ship_address_line2',
        'ship_village', 'ship_taluka', 'ship_district',
        'ship_city', 'ship_state', 'ship_pincode',
        'customer_note', 'invoice_id', 'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'placed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /* --------------------------------------------------------- the trail */

    /**
     * Every step this order took (§16).
     *
     * @return HasMany<OrderStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->orderBy('id');
    }

    /**
     * Record status changes, wherever they come from.
     *
     * An observer rather than a call in each service, because the paths that
     * move an order are many - the POS, the kitchen screen, the guest's own
     * phone, a scheduled sweep - and a log that depends on each of them
     * remembering has holes exactly where somebody was in a hurry.
     *
     * The duration is worked out here rather than derived later: the gap
     * between rows needs a window function or a self-join, and both get an
     * order's first and last rows wrong in ways nobody notices until the
     * report is in front of somebody.
     */
    protected static function booted(): void
    {
        static::created(function (Order $order) {
            $order->logStatus(null, (string) $order->status);
        });

        static::updated(function (Order $order) {
            if (! $order->wasChanged('status')) {
                return;
            }

            $order->logStatus(
                $order->getOriginal('status'),
                (string) $order->status,
            );
        });
    }

    /**
     * Append one step.
     *
     * Deliberately swallows its own failures. A trail is worth having and is
     * never worth failing an order for - a guest whose food is refused
     * because an audit row would not write is the wrong trade by a long way.
     */
    public function logStatus(?string $from, string $to, ?string $note = null): void
    {
        try {
            /*
             | reorder(), not latest().
             |
             | The relation carries orderBy('id') so the timeline reads top to
             | bottom. Chaining latest('id') onto that appends a SECOND order
             | clause and the first one still wins - so this returned the
             | OLDEST row, and every duration was measured from the order's
             | creation rather than from the step before it. The numbers came
             | out cumulative and entirely plausible, which is the worst way
             | for an arithmetic bug to look.
             */
            $previous = $this->statusLogs()->reorder('id', 'desc')->first();

            $since = $previous?->created_at ?? $this->created_at;

            OrderStatusLog::create([
                'shop_id' => $this->shop_id,
                'order_id' => $this->id,
                'from_status' => $from,
                'to_status' => $to,
                // Null on the first row: there is no previous status to have
                // sat in, and a zero there would drag every average down.
                'seconds_in_previous' => $from === null || $since === null
                    ? null
                    : max(0, $since->diffInSeconds(now())),
                'changed_by' => Auth::id(),
                'note' => $note,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }


    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function couponRedemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }


    /* ----------------------------------------------------- the restaurant */

    /** The sitting this order belongs to, for a dine-in one. */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return blank($type) ? $query : $query->where('order_type', $type);
    }

    /** Orders a kitchen still has work to do on (§9). */
    public function scopeInKitchen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::CONFIRMED, self::PREPARING]);
    }

    /**
     * Orders a kitchen screen should still be showing.
     *
     * Not cancelled, and - for a dine-in ticket - belonging to a sitting that
     * is still going on.
     *
     * The sitting is the part that was missing. A kitchen line is only ever
     * bumped by somebody pressing a button, so a ticket for a table that was
     * settled, written off or merged away with a line still outstanding stays
     * on the board for ever: the party has gone, the food is served or was
     * never made, and nobody will ever press anything on it again. They pile
     * up in the "Running late" count until the whole colour stops meaning
     * anything.
     *
     * They are hidden rather than bumped, deliberately. Marking uncooked food
     * "served" to tidy a screen would put a lie in the order history, which
     * the sales and kitchen reports then read as fact.
     */
    public function scopeOnTheBoard(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('status'), '!=', self::CANCELLED)
            ->where(fn (Builder $q) => $q
                ->whereNull($q->qualifyColumn('table_session_id'))
                ->orWhereHas('tableSession', fn (Builder $session) => $session->openOrBilled()));
    }

    public function isDineIn(): bool
    {
        return $this->order_type === self::DINE_IN;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->order_type] ?? Str::headline((string) $this->order_type);
    }

    /**
     * The next rung on the kitchen ladder, or null at the top.
     *
     * Read from KITCHEN_FLOW rather than a match, so adding a stage is one
     * edit in one place and the KDS button follows it.
     */
    public function nextKitchenStatus(): ?string
    {
        $at = array_search($this->status, self::KITCHEN_FLOW, true);

        if ($at === false) {
            // A web order mid-fulfilment, or a cancelled one. Not the
            // kitchen's ladder, so it has no next rung here.
            return null;
        }

        return self::KITCHEN_FLOW[$at + 1] ?? null;
    }

    /**
     * How long the kitchen has had it, in minutes.
     *
     * From when it was placed, not from when it was accepted: a ticket
     * nobody has accepted for twelve minutes is exactly the one the KDS
     * needs to shout about, and timing from acceptance would hide it.
     */
    public function kitchenMinutes(): int
    {
        $from = $this->placed_at ?? $this->created_at;

        return $from ? (int) $from->diffInMinutes(now()) : 0;
    }
    /* ------------------------------------------------------------ scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('order_number', 'like', "%{$term}%")
            ->orWhere('ship_recipient_name', 'like', "%{$term}%")
            ->orWhere('ship_mobile', 'like', "%{$term}%"));
    }

    public function scopeOfStatus(Builder $query, ?string $status): Builder
    {
        return blank($status) ? $query : $query->where('status', $status);
    }

    /* --------------------------------------------------------- behaviour */

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? Str::headline($this->status);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_PAID;
    }

    public function hasInvoice(): bool
    {
        return $this->invoice_id !== null;
    }

    /** Single-line shipping address, empty parts dropped. */
    public function shippingAddressLine(): string
    {
        return collect([
            $this->ship_address_line1,
            $this->ship_address_line2,
            $this->ship_village,
            $this->ship_taluka,
            $this->ship_district,
            $this->ship_city,
            $this->ship_state,
            $this->ship_pincode,
        ])->filter()->implode(', ');
    }

    /**
     * Mint the next order number for a shop.
     *
     * Not a locked counter the way Shop::nextNumber() is for invoices - an
     * order number carries no fiscal/GST obligation, so a plain padded id
     * is enough and needs no row lock on the shop.
     */
    public static function nextNumber(Shop $shop, int $orderId): string
    {
        return sprintf('ORD-%s-%06d', $shop->code, $orderId);
    }
}
