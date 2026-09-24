<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\WishlistItem;
use App\Services\CartService;
use App\Services\OrderService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The online shop: coupons, carts, orders and wishlists.
 *
 * Orders are placed the way a customer places them - items into the cart
 * through CartService, then OrderService::place - rather than by writing
 * rows into `orders`. That matters more here than anywhere else in these
 * seeders, because placing an order reserves stock and confirming one turns
 * it into an invoice; an order table filled in by hand would show the right
 * numbers on the list screen while the shelf and the sales ledger knew
 * nothing about it.
 *
 * The services want a Request because a guest's cart lives in the session.
 * Every customer here is signed in, so the cart is a database row and the
 * synthetic Request below is never actually read - it only satisfies the
 * signature.
 */
class DemoStorefrontSeeder extends Seeder
{
    use SeedsDemoData;

    public function __construct(
        private readonly CartService $cart,
        private readonly OrderService $orders,
    ) {}

    public function run(): void
    {
        $this->seedRandom(7);

        $this->coupons();
        $this->wishlists();
        $this->orders();
        $this->abandonedCarts();
    }

    private function coupons(): void
    {
        if ($this->alreadySeeded('Coupons', Coupon::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();

        $codes = [
            ['KHARIF10', Coupon::PERCENT, 10, 2000, 1500, 'Kharif season - 10% off'],
            ['RABI15', Coupon::PERCENT, 15, 3000, 2500, 'Rabi season - 15% off'],
            ['NEW200', Coupon::FIXED, 200, 0, 1000, 'First order discount'],
            ['SEED500', Coupon::FIXED, 500, 0, 5000, 'On seed orders above 5,000'],
            ['FERT5', Coupon::PERCENT, 5, 1000, 2000, 'Fertiliser bulk buy'],
            ['MONSOON20', Coupon::PERCENT, 20, 4000, 6000, 'Monsoon clearance'],
            ['DIWALI25', Coupon::PERCENT, 25, 5000, 8000, 'Diwali offer'],
            ['HOLI300', Coupon::FIXED, 300, 0, 2500, 'Holi offer'],
            ['LOYAL10', Coupon::PERCENT, 10, 1500, 1200, 'Repeat customer'],
            ['BULK1000', Coupon::FIXED, 1000, 0, 15000, 'Bulk order above 15,000'],
            ['SPRAY100', Coupon::FIXED, 100, 0, 800, 'On sprayers'],
            ['FARMER50', Coupon::FIXED, 50, 0, 500, 'Small farmer discount'],
            ['ONLINE12', Coupon::PERCENT, 12, 2500, 2000, 'Online orders only'],
            ['ADVANCE8', Coupon::PERCENT, 8, 1200, 1500, 'Prepaid orders'],
            ['GODOWN15', Coupon::PERCENT, 15, 3500, 4000, 'Godown pickup'],
            ['SUMMER400', Coupon::FIXED, 400, 0, 3500, 'Summer stocking'],
            ['REFER150', Coupon::FIXED, 150, 0, 1000, 'Referral reward'],
            ['ORGANIC10', Coupon::PERCENT, 10, 1800, 1500, 'On organic manure'],
            ['TRIAL75', Coupon::FIXED, 75, 0, 600, 'Trial pack offer'],
            ['CLEAR30', Coupon::PERCENT, 30, 6000, 10000, 'Clearance - expired scheme'],
        ];

        foreach ($codes as $index => [$code, $type, $value, $maxDiscount, $minOrder, $description]) {
            /*
             | A spread of live, not-yet-started and expired coupons, so the
             | list screen's status filter has all three to show and the
             | checkout's "this coupon has expired" path is reachable.
             */
            [$starts, $expires] = match (true) {
                $index >= 18 => [Carbon::today()->subMonths(4), Carbon::today()->subDays($this->between(5, 40))],
                $index >= 16 => [Carbon::today()->addDays($this->between(5, 20)), Carbon::today()->addMonths(3)],
                default => [Carbon::today()->subDays($this->between(10, 60)), Carbon::today()->addDays($this->between(20, 120))],
            };

            Coupon::query()->create([
                'shop_id' => $shop->id,
                'code' => $code,
                'description' => $description,
                'type' => $type,
                'value' => $value,
                'max_discount_amount' => $type === Coupon::PERCENT ? $maxDiscount : null,
                'min_order_amount' => $minOrder,
                'usage_limit' => $this->pick([null, 50, 100, 250]),
                'usage_limit_per_customer' => $this->pick([1, 1, 2, 3]),
                'starts_at' => $starts,
                'expires_at' => $expires,
                'is_active' => $index < 19,
            ]);
        }

        $this->say(sprintf('%d coupons.', count($codes)));
    }

    /**
     * Twenty online orders, spread across the fulfilment states.
     *
     * Confirming an order raises its invoice, so the ones taken all the way
     * to delivered also appear in sales - which is the join between the
     * storefront and the counter, and the thing worth being able to see.
     */
    private function orders(): void
    {
        if ($this->alreadySeeded('Online orders', Order::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();
        $request = Request::create('/');

        // Not restricted to customers with a saved address: the ones without
        // check out by typing one, which is the other half of the checkout
        // and would otherwise never be exercised.
        $customers = Customer::query()
            ->where('is_active', true)
            ->inRandomOrder()
            ->take(self::PER_MODULE)
            ->get();

        if ($customers->isEmpty()) {
            $this->say('Online orders skipped: there are no customers.');

            return;
        }

        $placed = 0;
        $confirmed = 0;
        $delivered = 0;
        $cancelled = 0;
        $refused = 0;

        foreach ($customers as $index => $customer) {
            $products = Product::query()
                ->active()
                ->where('is_published', true)
                ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 10))
                ->inRandomOrder()
                ->take($this->between(1, 3))
                ->get();

            if ($products->isEmpty()) {
                continue;
            }

            $this->cart->clear($shop, $customer, $request);

            foreach ($products as $product) {
                $this->cart->add($shop, $customer, $request, $product, $this->between(1, 4));
            }

            $saved = $customer->addresses()->value('id');

            try {
                $order = $this->orders->place($shop, $customer, $request, [
                    'address_id' => $saved,
                    'address' => $saved ? null : $this->typedAddress($customer),
                    'payment_method' => $this->chance(70) ? Order::COD : Order::ONLINE,
                    'customer_note' => $this->chance(25) ? 'Please deliver before the weekend.' : null,
                ]);
            } catch (RuntimeException) {
                // No stock left to reserve, or the address went away. Either
                // way the customer would have been told, not charged.
                continue;
            }

            $placed++;

            $order->forceFill([
                'placed_at' => $this->tradingHour($this->recentDate(45)),
            ])->save();

            $this->advance($order, $customer, $index, $confirmed, $delivered, $cancelled, $refused);
        }

        $this->say(sprintf(
            '%d online orders (%d confirmed, %d delivered, %d cancelled%s).',
            $placed,
            $confirmed,
            $delivered,
            $cancelled,
            $refused > 0 ? ', '.$refused.' stalled on a guard' : '',
        ));
    }

    /**
     * A delivery address typed at checkout, for a customer with none saved.
     *
     * Taken from the account rather than invented, because that is what the
     * checkout form pre-fills and what the courier would actually be given.
     *
     * @return array<string, mixed>
     */
    private function typedAddress(Customer $customer): array
    {
        return [
            'recipient_name' => $customer->name,
            'mobile' => $customer->mobile,
            'address_line1' => $customer->address_line1 ?: 'Near the bus stand',
            'village' => $customer->village,
            'taluka' => $customer->taluka,
            'district' => $customer->district,
            'city' => $customer->city,
            'state' => $customer->state,
            'pincode' => $customer->pincode,
        ];
    }

    /**
     * Walk one order as far along its lifecycle as its slot calls for.
     *
     * Every state is represented: pending ones for the shop to action,
     * confirmed and packed ones in hand, delivered ones done with, and a
     * couple cancelled - which returns the stock it had reserved.
     */
    private function advance(
        Order $order,
        Customer $customer,
        int $index,
        int &$confirmed,
        int &$delivered,
        int &$cancelled,
        int &$refused,
    ): void {
        $user = auth()->user();

        /*
         | One slot per state, cycling, so all six are represented whatever
         | the order count turns out to be. Cancellation is checked first and
         | on a coprime interval, so it does not always land on the same slot
         | and quietly starve one of the others.
         */
        $slot = $index % 6;

        try {
            if ($index % 11 === 5) {
                $this->orders->cancel($order, $this->pick([
                    'Customer called and cancelled.',
                    'Item out of stock at the godown.',
                ]), $user);
                $cancelled++;

                return;
            }

            // Pending: the queue the shop opens the screen for.
            if ($slot === 0) {
                return;
            }

            $this->orders->confirm($order, $user);
            $confirmed++;

            // Confirmed, before anything is packed.
            if ($slot === 1) {
                return;
            }

            $this->orders->updateStatus($order, Order::PACKING);

            if ($slot === 2) {
                return;
            }

            $this->orders->updateStatus($order, Order::SHIPPED);

            // Out for delivery and not yet paid for - which is what a COD
            // round looks like at any moment of the day.
            if ($slot === 3) {
                return;
            }

            /*
             | Paying is what raises the invoice, and markDelivered refuses an
             | order that has none - so this is not optional decoration before
             | delivery, it is the step that makes delivery possible.
             |
             | The amount has to be stated: OrderService passes it straight to
             | InvoiceService, which ignores a payment line of zero and would
             | leave the invoice wholly unpaid on the customer's account.
             */
            $this->orders->markPaid($order->refresh(), $user, [
                'amount' => (float) $order->grand_total,
                'method' => $order->payment_method === Order::COD ? 'cash' : 'upi',
                'transaction_ref' => 'ORD'.$this->between(100000, 999999),
            ]);

            $this->orders->markDelivered($order->refresh());
            $delivered++;
        } catch (RuntimeException) {
            /*
             | A guard refused a step: no stock left to reserve, or the
             | customer is at their credit limit. The order stays where it got
             | to, which is what would have happened in the shop.
             |
             | Deliberately RuntimeException and not Throwable - a Throwable
             | catch here silently swallowed a missing array key and left
             | every order stuck at "shipped", which is exactly the kind of
             | bug a seeder should be loud about.
             */
            $refused++;
        }
    }

    /**
     * Carts left sitting, and wishlists.
     *
     * An abandoned cart is not a failure state to hide - it is what the
     * storefront's own screens report on, and there is nothing to report
     * with every cart converted into an order.
     */
    private function abandonedCarts(): void
    {
        $shop = CurrentShop::get();
        $request = Request::create('/');

        /*
         | Any customer will do. Placing an order empties the cart, so items
         | added now are genuinely left standing - and a customer who has
         | ordered before is exactly who abandons a second cart.
         */
        $customers = Customer::query()
            ->where('is_active', true)
            ->inRandomOrder()
            ->take(6)
            ->get();

        $made = 0;

        foreach ($customers as $customer) {
            $products = Product::query()
                ->active()
                ->where('is_published', true)
                ->inRandomOrder()
                ->take($this->between(1, 3))
                ->get();

            foreach ($products as $product) {
                $this->cart->add($shop, $customer, $request, $product, $this->between(1, 3));
            }

            if ($products->isNotEmpty()) {
                $made++;
            }
        }

        $this->say(sprintf('%d carts left standing.', $made));
    }

    private function wishlists(): void
    {
        if ($this->alreadySeeded('Wishlist items', WishlistItem::query()->count())) {
            return;
        }

        $shop = CurrentShop::get();
        $customers = Customer::query()->where('is_active', true)->inRandomOrder()->take(12)->get();
        $products = Product::query()->active()->where('is_published', true)->get();

        if ($products->isEmpty()) {
            return;
        }

        $made = 0;

        foreach ($customers as $customer) {
            foreach ($products->random(min($this->between(1, 4), $products->count())) as $product) {
                WishlistItem::query()->firstOrCreate([
                    'shop_id' => $shop->id,
                    'customer_id' => $customer->id,
                    'product_id' => $product->id,
                ]);

                $made++;
            }
        }

        $this->say(sprintf('%d wishlist items.', $made));
    }
}
