<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Erp\ErpClient;
use App\Services\Erp\ErpLink;
use App\Services\Erp\ErpUnavailable;
use App\Support\CurrentShop;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Khana\Kit\Support\Money;
use Khana\Kit\Support\Ulid;
use Throwable;

/**
 * The panels that show something ERP knows.
 *
 * Three of them, each a fragment its page asks for once it has drawn: the
 * unpaid supplier bills on the register's paid-out card, stock and cost on a
 * menu item, and the day's food cost on the home screen. Every screen in CRM
 * that reads ERP is in this file, and nothing in this file writes.
 *
 * They share three rules, which is why they share a controller:
 *
 *   - An outlet that is not linked to ERP gets a 404 and no call is made.
 *     Its pages never ask - they are not drawn with the panel at all - so
 *     this is the door being shut as well as unmarked.
 *
 *   - ERP failing is not an error here. The panel answers 200 with "ERP is
 *     not reachable right now", because the page around it has already
 *     drawn and is working, and that is exactly what the person should be
 *     told.
 *
 *   - What ERP sends is translated before it is drawn: paise into rupees,
 *     a size's ULID into the name this menu gives it. Whatever is not what
 *     the contract promised is dropped rather than printed.
 */
class ErpPanelController extends Controller
{
    public function __construct(
        private readonly ErpClient $erp,
        private readonly ErpLink $link,
    ) {}

    /* ------------------------------------------------------ the register */

    /**
     * The supplier bills still owed, for the cashier to pick the one the
     * cash is paying. Oldest due first - ERP's order, kept.
     */
    public function bills(CashRegister $register): View
    {
        $shop = $this->linkedOr404($register->shop);

        try {
            $bills = $this->erp->openBills($shop->ulid);
        } catch (ErpUnavailable) {
            return view('admin.registers._bills', ['bills' => null]);
        }

        return view('admin.registers._bills', [
            'bills' => collect($bills)
                // A bill without an id cannot be named to ERP afterwards.
                ->filter(fn (array $bill) => Ulid::isValid($bill['bill_id'] ?? null))
                ->map(fn (array $bill) => [
                    'id' => $bill['bill_id'],
                    'supplier' => trim((string) ($bill['supplier_name'] ?? '')) ?: 'Unknown supplier',
                    'number' => trim((string) ($bill['bill_no'] ?? '')),
                    'due_on' => self::day($bill['due_on'] ?? null),
                    // To the paisa and as a string: it is put into the
                    // amount box as it stands.
                    'due' => Money::paise(max(0, self::whole($bill['due_paise'] ?? null) ?? 0))->toRupees(),
                ])
                ->values()
                ->all(),
        ]);
    }

    /* ------------------------------------------------------ the menu item */

    /**
     * Stock on hand and recipe cost for one dish, size by size.
     */
    public function stock(Product $product): View
    {
        $shop = $this->linkedOr404(CurrentShop::get());

        // A dish with no public id has never been sent anywhere, so ERP
        // cannot have heard of it. No need to ask.
        if (! Ulid::isValid($product->ulid)) {
            return view('admin.products._erp-stock', ['state' => 'unknown', 'rows' => []]);
        }

        try {
            $stock = $this->erp->itemStock($shop->ulid, $product->ulid);
        } catch (ErpUnavailable) {
            return view('admin.products._erp-stock', ['state' => 'unreachable', 'rows' => []]);
        }

        if ($stock === null) {
            return view('admin.products._erp-stock', ['state' => 'unknown', 'rows' => []]);
        }

        $rows = $this->sizes($product, $stock['sizes'] ?? null);

        return view('admin.products._erp-stock', [
            'state' => ($stock['has_recipe'] ?? false) === true && $rows !== [] ? 'costed' : 'no_recipe',
            'rows' => $rows,
        ]);
    }

    /**
     * ERP's sizes under this menu's names and in this menu's order.
     *
     * ERP keys a size by the ULID CRM gave it, and its own copy of the name
     * can lag a rename. The person reading this panel is looking at CRM's
     * menu, so CRM's word for the size is the one that will not confuse
     * them.
     *
     * @return list<array{name: ?string, portions: ?int, cost: ?float, percent: ?float, on_sale: bool}>
     */
    private function sizes(Product $product, mixed $sizes): array
    {
        if (! is_array($sizes)) {
            return [];
        }

        $variants = $product->variants()->get(['id', 'ulid', 'name'])->values();
        $position = $variants->pluck('ulid')->flip();
        $names = $variants->pluck('name', 'ulid');

        return collect($sizes)
            ->filter(fn ($size) => is_array($size))
            ->map(function (array $size) use ($names, $position) {
                $variant = $size['variant_id'] ?? null;

                return [
                    // Null is the dish itself: one that comes one way.
                    'name' => $variant === null ? null : ($names[$variant] ?? 'A size no longer on this menu'),
                    'order' => $variant === null ? -1 : ($position[$variant] ?? PHP_INT_MAX),
                    'portions' => self::portions($size['portions_available'] ?? null),
                    'cost' => self::rupees($size['cost_paise'] ?? null),
                    'percent' => self::percent($size['food_cost_percent'] ?? null),
                    'on_sale' => ($size['available'] ?? true) !== false,
                ];
            })
            ->sortBy('order')
            ->map(fn (array $row) => collect($row)->except('order')->all())
            ->values()
            ->all();
    }

    /* ---------------------------------------------------- the home screen */

    /**
     * What today's sales earned and what the food in them cost.
     *
     * "Today" is ERP's: the outlet's current business day by Accounts' time
     * zone and day cutoff, the same clock the sales it is adding up were
     * stamped with. The panel prints the date so nobody has to guess it.
     */
    public function foodCost(): View
    {
        $shop = $this->linkedOr404(CurrentShop::get());

        try {
            $day = $this->erp->foodCost($shop->ulid);
        } catch (ErpUnavailable) {
            return view('admin.dashboard._food-cost', ['day' => null]);
        }

        return view('admin.dashboard._food-cost', [
            'day' => [
                'date' => self::day($day['business_date'] ?? null),
                'percent' => self::percent($day['food_cost_percent'] ?? null),
                'cost' => self::rupees($day['cost_paise'] ?? null) ?? 0.0,
                'sales' => self::rupees($day['sales_paise'] ?? null) ?? 0.0,
                'uncosted' => max(0, self::whole($day['uncosted'] ?? null) ?? 0),
            ],
        ]);
    }

    /* --------------------------------------------------------- internals */

    /**
     * The outlet, once it is known to be linked to ERP - or a 404.
     *
     * Not a 403: nothing was refused. For an outlet with CRM alone, and in
     * the All-shops view where there is no one outlet to ask about, there is
     * simply no such panel.
     */
    private function linkedOr404(?Shop $shop): Shop
    {
        abort_unless($this->link->linked($shop), 404);

        return $shop;
    }

    /** A whole number as the contract sends one, or null for anything else. */
    private static function whole(mixed $value): ?int
    {
        return is_int($value) || (is_float($value) && floor($value) === $value) ? (int) $value : null;
    }

    /** Whole paise as the rupees CRM's screens work in. */
    private static function rupees(mixed $paise): ?float
    {
        $paise = self::whole($paise);

        return $paise === null ? null : $paise / 100;
    }

    /** "32.50" as 32.5. */
    private static function percent(mixed $value): ?float
    {
        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /**
     * How many whole portions the stock covers. ERP sends four decimal
     * places; nobody is served 0.4 of a biryani, so it is rounded down, and
     * a shortfall is none rather than a minus.
     */
    private static function portions(mixed $value): ?int
    {
        return is_string($value) && is_numeric($value) ? max(0, (int) floor((float) $value)) : null;
    }

    /** A YYYY-MM-DD business date, or null when it is absent or malformed. */
    private static function day(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }
}
