<?php

namespace App\Support;

use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Support\Carbon;

/**
 * Which price applies right now (SRS 8, 16).
 *
 * ---------------------------------------------------------------------------
 * Inert until somebody makes a list
 * ---------------------------------------------------------------------------
 *
 * The rule this class lives under. `priceFor()` returns null when no list
 * applies, and every caller treats null as "use the product's own price" - so
 * an install with no price lists behaves exactly as it did before this
 * existed, down to the rupee.
 *
 * That matters because the override reaches everything: the counter, the QR
 * menu, the API and every order placed through any of them. A thing with that
 * reach has to do nothing by default.
 *
 * ---------------------------------------------------------------------------
 * Memoised, hard
 * ---------------------------------------------------------------------------
 *
 * A menu page asks this once per dish. Without a per-request memo that is a
 * query per row on the one screen a hungry guest is staring at, so the
 * applicable lists and their items are loaded once and answered from memory.
 */
final class PriceLists
{
    /** shop id => the lists running now, best first. */
    private static array $lists = [];

    /** "shopId:channel:productId:variantId" => float|null */
    private static array $answers = [];

    /**
     * The overridden price, or null when nothing applies.
     *
     * @param  float  $normal  what the dish costs without any list
     */
    public static function priceFor(
        int $productId,
        ?int $variantId,
        float $normal,
        ?int $shopId,
        ?string $channel = null,
    ): ?float {
        if ($shopId === null) {
            return null;
        }

        $key = $shopId.':'.($channel ?? '-').':'.$productId.':'.($variantId ?? '-');

        if (array_key_exists($key, self::$answers)) {
            $answer = self::$answers[$key];

            return $answer === null ? null : round($answer, 2);
        }

        $answer = null;

        foreach (self::running($shopId, $channel) as $list) {
            /*
             | A variant's own row wins over the dish's, exactly as a variant
             | price wins over a product price everywhere else. Falling back
             | to the dish's row is what lets a list say "everything on the
             | beer menu is half price" without naming every size.
             */
            $item = $list->items->first(fn (PriceListItem $row) => $row->product_id === $productId
                    && $row->product_variant_id === $variantId)
                ?? $list->items->first(fn (PriceListItem $row) => $row->product_id === $productId
                    && $row->product_variant_id === null);

            if ($item === null) {
                continue;
            }

            // Highest priority first, so the first list with a row for this
            // dish is the answer.
            $answer = $item->apply($normal);
            break;
        }

        self::$answers[$key] = $answer;

        return $answer === null ? null : round($answer, 2);
    }

    /**
     * The lists running for a branch at this moment, best first.
     *
     * @return \Illuminate\Support\Collection<int, PriceList>
     */
    private static function running(int $shopId, ?string $channel)
    {
        $cacheKey = $shopId.':'.($channel ?? '-');

        if (isset(self::$lists[$cacheKey])) {
            return self::$lists[$cacheKey];
        }

        $now = Carbon::now();

        return self::$lists[$cacheKey] = PriceList::query()
            ->withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->active()
            ->with('items')
            ->orderByDesc('priority')
            // The id breaks a tie, so two lists of equal priority at least
            // give the same answer every time rather than a coin toss.
            ->orderBy('id')
            ->get()
            ->filter(fn (PriceList $list) => $list->appliesAt($now, $channel))
            ->values();
    }

    /**
     * Forget everything - between tests, and after a list is edited.
     *
     * A price memo that outlived an edit would keep a happy hour running
     * after somebody switched it off, which is the one direction of this bug
     * that costs money.
     */
    public static function forget(): void
    {
        self::$lists = [];
        self::$answers = [];
    }
}
