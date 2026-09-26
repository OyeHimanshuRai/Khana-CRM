<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * robots.txt and sitemap.xml (§19).
 *
 * ---------------------------------------------------------------------------
 * Generated, not static files
 * ---------------------------------------------------------------------------
 *
 * Both could be text files in public/. They are not, for one reason: a static
 * sitemap is a list somebody has to remember to update, and the first thing it
 * does on a real install is go stale - a storefront opens, a blog post is
 * written, and the file still lists the three pages that existed on the day it
 * was deployed.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately kept out of the index
 * ---------------------------------------------------------------------------
 *
 * Most of this application has no business in a search result, and the reasons
 * differ:
 *
 *   /admin      a back office. Indexing it publishes a login page and a list
 *               of a customer's own screens.
 *   /t          a guest's table session, which only means anything to the
 *               phone holding the cookie.
 *   /signup     a form. Nothing on it is content, and a crawler following it
 *               is a crawler filling in a form.
 *   ?utm=, ?q=  query strings multiply one page into hundreds of near
 *               duplicates and split its ranking between them.
 *
 * The public marketing page and each active shop's storefront front door are
 * what remain, and they are what the sitemap lists.
 */
class SeoController extends Controller
{
    /**
     * What a crawler may open.
     *
     * Plain text, and `Disallow` rather than `noindex`: a robots rule keeps a
     * crawler off the URL entirely, which is what matters for a back office -
     * a `noindex` on the page would still mean it was fetched, logged and
     * rate-limited against.
     */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            '',
            'Disallow: /admin',
            'Disallow: /signup',
            'Disallow: /t',
            'Disallow: /payments',
            '',
            '# Carts, accounts and checkouts belong to one shopper.',
            'Disallow: /shop/*/cart',
            'Disallow: /shop/*/checkout',
            'Disallow: /shop/*/account',
            'Disallow: /shop/*/orders',
            'Disallow: /shop/*/wishlist',
            'Disallow: /shop/*/login',
            'Disallow: /shop/*/register',
            '',
            // One page per URL, not one per campaign tag. The second
            // wildcard is load-bearing: `/*?utm_` only matches a link whose
            // campaign tag happens to be the first parameter, and the ones
            // that arrive in the wild rarely are.
            '# One page per URL, not one per campaign tag.',
            'Disallow: /*?*utm_',
            'Disallow: /*?*q=',
            '',
            'Sitemap: '.route('sitemap'),
            '',
        ];

        return response(implode(PHP_EOL, $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Everything worth indexing, with the date it last changed.
     *
     * `lastmod` is read off the rows rather than set to today. A sitemap that
     * claims every page changed this morning is one a crawler stops believing,
     * and then re-crawls on its own schedule instead of on the hint.
     */
    public function sitemap(): Response
    {
        $urls = [[
            'loc' => route('landing'),
            'changefreq' => 'weekly',
            'priority' => '1.0',
            'lastmod' => null,
        ]];

        /*
         | Each shop's storefront front door.
         |
         | Only active ones, and only the home page: the catalogue beneath it
         | is linked from there and a crawler follows links perfectly well.
         | Listing every product would make this file the biggest response
         | the application serves, for no ranking anybody gets.
         */
        foreach (Shop::query()->where('is_active', true)->orderBy('id')->get() as $shop) {
            $urls[] = [
                'loc' => route('shop.home', ['shop' => $shop->slug]),
                'changefreq' => 'daily',
                'priority' => '0.8',
                'lastmod' => $shop->updated_at,
            ];
        }

        /*
         | The blog is not listed, deliberately.
         |
         | Posts are drawn as sections of the landing page and have no address
         | of their own, so the only URL that could be emitted is the landing
         | page with a `#blog-<slug>` on the end - and a crawler drops the
         | fragment before it asks for anything. Two hundred posts became two
         | hundred copies of one entry, each overwriting the last one's
         | lastmod. If a post is ever given a page of its own, this is where
         | it goes back.
         */

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>'.e($url['loc']).'</loc>';

            if ($url['lastmod'] instanceof Carbon) {
                $xml[] = '    <lastmod>'.$url['lastmod']->toAtomString().'</lastmod>';
            }

            $xml[] = '    <changefreq>'.$url['changefreq'].'</changefreq>';
            $xml[] = '    <priority>'.$url['priority'].'</priority>';
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return response(implode(PHP_EOL, $xml), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

}
