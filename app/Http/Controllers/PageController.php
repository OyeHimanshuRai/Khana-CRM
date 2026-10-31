<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\SiteBrand;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * A standalone public page (privacy policy, terms, refund policy...).
 *
 * Only active pages exist as far as the public is concerned; an inactive one
 * answers 404, exactly as if it had never been written.
 */
class PageController extends Controller
{
    public function show(string $slug): View
    {
        $page = Page::query()->active()->where('slug', $slug)->firstOrFail();
        $site = SiteBrand::get();
        $plainContent = Str::squish(strip_tags(preg_replace(
            '/<br\s*\/?>|<\/(?:h[1-6]|p|li|div|blockquote|tr)>/i',
            ' ',
            (string) $page->html(),
        ) ?? ''));

        return view('pages.show', [
            'page' => $page,
            'site' => $site,
            'metaDescription' => $page->meta_description ?: Str::limit(
                $plainContent ?: 'Read '.$page->title.' information from '.$site['name'].'.',
                160,
            ),
            'footerPages' => Page::query()->footer()->get(['id', 'title', 'slug']),
        ]);
    }
}
