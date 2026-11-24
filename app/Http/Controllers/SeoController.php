<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Illuminate\Http\Response;

/**
 * What a search engine asks for first: /robots.txt and /sitemap.xml.
 *
 * Both are built from the site's own address, so they are right on any domain, and the
 * sitemap lists the pages the owner publishes under Site Pages as well as the fixed ones.
 */
class SeoController extends Controller
{
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            '',
            '# Private screens and one-off links: nothing to index.',
            'Disallow: /superadmin/',
            'Disallow: /login',
            'Disallow: /forgot-password',
            'Disallow: /reset-password/',
            'Disallow: /email/',
            'Disallow: /impersonate/',
            'Disallow: /billing/',
            'Disallow: /payments/',
            'Disallow: /api/',
            'Disallow: /broadcasting/',
            'Disallow: /t/',
            'Disallow: /clear-all',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        // address => [how often it changes, how important it is]
        $fixed = [
            rtrim(url('/'), '/').'/' => ['weekly', '1.0'],
            route('pricing') => ['weekly', '0.9'],
            route('landing.features') => ['monthly', '0.8'],
            route('landing.how') => ['monthly', '0.8'],
            route('landing.billing') => ['monthly', '0.8'],
            route('landing.faq') => ['monthly', '0.7'],
            route('demo') => ['monthly', '0.7'],
            route('register') => ['monthly', '0.7'],
        ];

        $urls = [];

        foreach ($fixed as $loc => [$freq, $priority]) {
            $urls[] = ['loc' => $loc, 'freq' => $freq, 'priority' => $priority, 'lastmod' => null];
        }

        foreach (Page::query()->active()->orderBy('sort_order')->orderBy('title')->get(['slug', 'updated_at']) as $page) {
            $urls[] = ['loc' => route('page.show', $page->slug), 'freq' => 'yearly', 'priority' => '0.4', 'lastmod' => $page->updated_at?->toAtomString()];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $u) {
            $xml .= "  <url>\n    <loc>".e($u['loc'])."</loc>\n";

            if ($u['lastmod']) {
                $xml .= '    <lastmod>'.e($u['lastmod'])."</lastmod>\n";
            }

            $xml .= '    <changefreq>'.$u['freq']."</changefreq>\n    <priority>".$u['priority']."</priority>\n  </url>\n";
        }

        return response($xml.'</urlset>'."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
