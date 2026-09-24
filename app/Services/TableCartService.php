<?php

namespace App\Services;

use App\Models\Modifier;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TableCartItem;
use App\Models\TableSession;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The cart a table builds before it sends anything to the kitchen (§3.5, §3.6).
 *
 * Every rule about what may go in it lives here, and all of them are checked
 * on the server. The menu page disables what it can, but a cart posted by hand
 * must not be able to buy a pizza with four crusts or a dish that sold out
 * ten minutes ago - and the page that renders the buttons is the last place
 * that should be trusted to enforce it.
 *
 * Nothing here prices anything into the row. A cart is re-priced from the live
 * menu every time it is read: the snapshot happens when the order is placed,
 * which is the moment a price stops being a quote and becomes a commitment.
 */
class TableCartService
{
    /** One line may not run away with the kitchen. */
    private const MAX_QUANTITY = 30;

    /** Nor may one table, by accident or by script. */
    private const MAX_LINES = 60;

    /**
     * Put a dish in the cart.
     *
     * Adding the same dish, in the same size, with the same add-ons and the
     * same note bumps the quantity rather than making a second line - three
     * taps on a plain naan should read as three naans, not three rows. A
     * different note or a different topping is a genuinely different thing
     * and gets its own line, because the kitchen has to make it separately.
     *
     * @param  array<int, mixed>  $optionIds
     */
    public function add(
        TableSession $session,
        Product $product,
        ?int $variantId = null,
        int $quantity = 1,
        array $optionIds = [],
        ?string $note = null,
        ?string $device = null,
    ): TableCartItem {
        $this->assertSessionTakesOrders($session);

        $quantity = max(1, min($quantity, self::MAX_QUANTITY));

        $variant = $this->resolveVariant($product, $variantId);
        $options = $this->resolveOptions($product, $optionIds);

        if (! $product->isOrderable()) {
            throw new RuntimeException(
                $product->unavailableReason() ?? 'That is not available right now.'
            );
        }

        if ($variant !== null && ! $variant->is_available) {
            throw new RuntimeException("{$variant->name} is not available right now.");
        }

        $note = filled($note) ? mb_substr(trim($note), 0, 250) : null;

        /*
         | Matched on everything that makes two lines the same order. The
         | option ids are sorted first, because [3,7] and [7,3] are the same
         | pizza and a caller that ticked them in a different order should not
         | get a second row.
         */
        $signature = $options->pluck('id')->sort()->values()->all();

        $existing = $session->cartItems()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->where('note', $note)
            ->get()
            ->first(fn (TableCartItem $item) => collect($item->option_ids ?? [])
                ->map(fn ($id) => (int) $id)->sort()->values()->all() === $signature);

        if ($existing !== null) {
            $existing->forceFill([
                'quantity' => min($existing->quantity + $quantity, self::MAX_QUANTITY),
            ])->save();

            return $existing;
        }

        if ($session->cartItems()->count() >= self::MAX_LINES) {
            throw new RuntimeException(
                'That is a lot of food. Please send this order through before adding more.'
            );
        }

        $item = new TableCartItem([
            'shop_id' => $session->shop_id,
            'table_session_id' => $session->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
            'note' => $note,
            'option_ids' => $signature ?: null,
            'added_by' => $device,
        ]);

        $item->save();

        return $item;
    }

    /** Change how many of a line there are. Zero removes it. */
    public function setQuantity(TableSession $session, TableCartItem $item, int $quantity): void
    {
        $this->assertSessionTakesOrders($session);
        $this->assertOwns($session, $item);

        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $item->forceFill(['quantity' => min($quantity, self::MAX_QUANTITY)])->save();
    }

    public function remove(TableSession $session, TableCartItem $item): void
    {
        $this->assertOwns($session, $item);

        $item->delete();
    }

    public function clear(TableSession $session): void
    {
        $session->cartItems()->delete();
    }

    /**
     * The cart, priced, with anything unorderable flagged rather than dropped.
     *
     * Dropping a line that has sold out since it was added would have the
     * total change under the guest with no explanation. It is kept, marked,
     * and refused at the moment they try to send it.
     *
     * @return array{lines: Collection<int, TableCartItem>, total: float, count: int, blocked: Collection<int, TableCartItem>}
     */
    public function summary(TableSession $session, string $channel = 'dine_in'): array
    {
        $lines = $session->cartItems()
            ->with(['product.taxRate', 'variant'])
            ->orderBy('id')
            ->get();

        $blocked = $lines->reject(fn (TableCartItem $item) => $item->isOrderable())->values();

        return [
            'lines' => $lines,
            'blocked' => $blocked,
            'count' => (int) $lines->sum('quantity'),
            'total' => round(
                $lines->sum(fn (TableCartItem $item) => $item->lineTotal($channel, $session->shop_id)),
                2,
            ),
        ];
    }

    /* ----------------------------------------------------------- the rules */

    /**
     * A size must be named where a dish has sizes, and must belong to it.
     *
     * This is the check that stops a table being billed for a Full and served
     * a Half. There is deliberately no fallback to the default size: a caller
     * that did not say which one did not ask the guest either.
     */
    private function resolveVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        $hasSizes = $product->variants()->exists();

        if (! $hasSizes) {
            // A size sent for a dish that has none is a stale menu page, not
            // a reason to refuse the order.
            return null;
        }

        if ($variantId === null) {
            throw new RuntimeException('Choose a size for '.$product->name.'.');
        }

        $variant = $product->variants()->whereKey($variantId)->first();

        if ($variant === null) {
            throw new RuntimeException('That size is no longer on the menu.');
        }

        return $variant;
    }

    /**
     * The add-ons ticked, checked against what the dish actually asks.
     *
     * Three things are enforced, and all three are ways a hand-posted cart
     * would otherwise get through:
     *
     *   1. every option belongs to a question this dish asks
     *   2. every option is still available
     *   3. each question's min/max is satisfied - Modifier::accepts()
     *
     * @param  array<int, mixed>  $optionIds
     * @return Collection<int, ModifierOption>
     */
    private function resolveOptions(Product $product, array $optionIds): Collection
    {
        $questions = $product->modifiers()->with('options')->where('is_active', true)->get();

        $wanted = collect($optionIds)->map(fn ($id) => (int) $id)->filter()->unique();

        $allowed = $questions->flatMap(fn (Modifier $m) => $m->options)->keyBy('id');

        $chosen = $wanted->map(function (int $id) use ($allowed) {
            $option = $allowed->get($id);

            if ($option === null) {
                throw new RuntimeException('One of those choices is not on this dish.');
            }

            if (! $option->is_available) {
                throw new RuntimeException($option->name.' is not available right now.');
            }

            return $option;
        });

        foreach ($questions as $question) {
            $count = $chosen->where('modifier_id', $question->id)->count();

            if (! $question->accepts($count)) {
                throw new RuntimeException($question->name.': '.$question->ruleLabel().'.');
            }
        }

        return $chosen->values();
    }

    private function assertSessionTakesOrders(TableSession $session): void
    {
        if (! $session->isOpen()) {
            throw new RuntimeException(
                'The bill for this table has been raised. Please ask a member of staff.'
            );
        }
    }

    /**
     * A line belongs to the sitting it is being edited from.
     *
     * Not paranoia: the item id is in the form the guest posts, and every
     * phone in the restaurant can reach this endpoint.
     */
    private function assertOwns(TableSession $session, TableCartItem $item): void
    {
        if ((int) $item->table_session_id !== (int) $session->id) {
            throw new RuntimeException('That item is not on this table.');
        }
    }
}
