<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * What a storefront page tells a search engine and a share preview.
 *
 * The storefront shipped with a <title> and nothing else. Its <head> was a
 * charset, a viewport, a CSRF token and two stylesheets, so every outlet's
 * shop - the page its customers are actually sent - went into search results
 * with no description, and unfurled in WhatsApp as a bare URL with the shop's
 * name printed twice. The admin had been collecting meta_title and
 * meta_description on every product since the beginning and nothing read them.
 *
 * One place rather than a block of tags per view, because the rules are the
 * same everywhere and only the values differ: the outlet is the site, its own
 * URL is the canonical one, and a page a customer reaches by signing in is
 * never indexed.
 */
final class StorefrontSeo
{
    /**
     * The longest description a search result will show before truncating it
     * itself. Better a sentence that ends than one that stops.
     */
    private const DESCRIPTION_LIMIT = 160;

    /**
     * The only storefront pages worth putting in a search result.
     *
     * A list of what may be indexed rather than a list of what may not, so a
     * page added next year is private until somebody decides otherwise. The
     * wrong way round is how a stranger's basket, or an order confirmation
     * with a phone number on it, ends up on a results page.
     */
    private const INDEXABLE = [
        'shop.home',
        'shop.catalog',
        'shop.category',
        'shop.product',
    ];

    /**
     * @param  string  $pageTitle  whatever the view put in @section('title')
     * @param  array<string, mixed>  $page  a controller's overrides
     * @return array<string, mixed>
     */
    public static function resolve(Shop $shop, string $pageTitle = '', array $page = []): array
    {
        /*
         | A controller's title beats the view's, because the only thing that
         | overrides it is a product's own meta_title - which somebody typed
         | on purpose, in preference to the name the view prints.
         */
        $pageTitle = trim((string) ($page['title'] ?? $pageTitle));
        $place = trim((string) ($shop->city ?: $shop->state));

        return [
            /*
             | "Cart · Dhaba 91" rather than "Dhaba 91 · Dhaba 91": the home
             | and catalogue pages set no section title, and the layout was
             | printing the fallback and the shop name side by side.
             */
            'title' => $pageTitle !== '' && $pageTitle !== $shop->name
                ? $pageTitle.' · '.$shop->name
                : $shop->name,

            'description' => self::trim(
                $page['description']
                    ?? ($place !== ''
                        ? "Order online from {$shop->name} in {$place}."
                        : "Order online from {$shop->name}.")
            ),

            /*
             | The address to index, and the one a share should carry. Without
             | it every filter, sort and utm parameter is a separate page to a
             | crawler and one outlet competes with itself.
             */
            'canonical' => $page['canonical'] ?? url()->current(),

            // A picture that does not resolve renders as a broken box in the
            // preview, which is worse than a preview with no picture in it.
            'image' => $page['image'] ?? $shop->logoUrl(),

            'robots' => ($page['index'] ?? in_array(Route::currentRouteName(), self::INDEXABLE, true))
                ? 'index, follow'
                : 'noindex, nofollow',

            'jsonLd' => $page['jsonLd'] ?? null,
        ];
    }

    /**
     * The shop itself, for the pages that are about the shop.
     *
     * Only what the record actually holds: an address with three of its five
     * lines missing is worse than no address, because a crawler will believe
     * it.
     *
     * @return array<string, mixed>
     */
    public static function shopGraph(Shop $shop): array
    {
        $address = array_filter([
            'streetAddress' => trim((string) ($shop->address_line1.' '.$shop->address_line2)) ?: null,
            'addressLocality' => $shop->city ?: null,
            'addressRegion' => $shop->state ?: null,
            'postalCode' => $shop->pincode ?: null,
            'addressCountry' => $shop->country ?: 'IN',
        ]);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            'name' => $shop->name,
            'url' => route('shop.home', $shop),
            'image' => $shop->logoUrl(),
            'telephone' => $shop->phone ?: null,
            'email' => $shop->email ?: null,
            'currenciesAccepted' => $shop->currency ?: null,
            'address' => count($address) > 1 ? ['@type' => 'PostalAddress'] + $address : null,
        ], static fn ($value) => $value !== null && $value !== []);
    }

    /**
     * A sentence, from whatever the admin typed - which may be a paragraph of
     * HTML pasted out of a word processor.
     */
    public static function trim(?string $text): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));

        return $text === '' ? null : Str::limit($text, self::DESCRIPTION_LIMIT);
    }
}
