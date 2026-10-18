<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\Integration;
use App\Models\OutletType;
use App\Models\Showcase;
use App\Models\Slider;
use App\Models\Testimonial;
use App\Models\Service;
use App\Models\Setting;
use App\Support\Accounts;
use App\Support\Modules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The product's own landing page.
 *
 * ---------------------------------------------------------------------------
 * What this page is selling
 * ---------------------------------------------------------------------------
 *
 * This is **not** a restaurant's website. It is the marketing page for the
 * platform itself - the thing a restaurant owner reads before deciding to sign
 * up. It answers three questions, in this order, because that is the order
 * they are actually asked in:
 *
 *   1. What kind of product is this?     the hero
 *   2. What can it do?                   the modules
 *   3. What else do you do for me?       services
 *
 * What it costs, and opening an account, are Khana Accounts' - it holds the
 * plans and the signup. This page links there when it knows the address and
 * leaves the buttons out when it does not.
 *
 * A guest's *restaurant* menu is a different thing entirely and lives behind
 * the table QR - see the `t/` routes.
 *
 * ---------------------------------------------------------------------------
 * The feature list is the real one
 * ---------------------------------------------------------------------------
 *
 * `Modules::catalogue()` is not a marketing copy deck. It is the same list the
 * sidebar filters against and that a shop switches lines of business on with.
 * So a feature advertised here is a feature that exists, and one that is
 * switched off platform-wide stops being advertised on the same deploy. A
 * hand-written list on a marketing page is a promise nobody updates when the
 * code changes.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        $site = $this->site();
        $faqs = Faq::query()->active()->orderBy('sort_order')->limit(8)->get();

        return view('landing.index', [
            'site' => $site,
            'strip' => $this->strip(),
            'modules' => $this->modules(),
            'services' => Service::query()->active()->orderBy('sort_order')->limit(6)->get(),
            'faqs' => $faqs,
            'steps' => $this->steps(),

            // Null when this install has not been told where Accounts is;
            // the view then leaves the "open an account" buttons out.
            'signupUrl' => Accounts::url('/signup'),
            'partnerAppUrl' => is_file(storage_path('app/downloads/restro-one-partner.apk')) ? route('partner-app.download') : null,

            /*
             | What a search engine reads instead of guessing from the markup.
             | Built from the rows above, so it cannot describe a question the
             | page does not show. See structuredData().
             */
            'jsonLd' => $this->structuredData($site, $faqs),

            /*
             | The sections added with the admin modules behind them. They
             | share `active()->ordered()` from the LandingContent trait, and
             | each renders only when it has rows - see the view.
             */

            /*
             | Trust Numbers (§19) are deliberately not here.
             |
             | The module is intact - rows, admin screen, and
             | LandingStat::display(), which counts a live figure rather than
             | printing a marketer's typed one. What this page does not do is
             | draw them, because a counted figure is honest and that is the
             | problem on a young install: "1 Outlet running on it" is true,
             | and it is the worst sentence available to put under a headline.
             |
             | Not queried rather than queried and ignored, so nothing here
             | costs a visitor a round trip for rows no one renders. To bring
             | them back, load LandingStat::query()->active()->ordered() and
             | render each as `<strong>{display}</strong> {label}` - the shape
             | LandingContentTest asserts.
             */

            'testimonials' => Testimonial::query()->active()->ordered()->limit(6)->get(),
            'outletTypes' => OutletType::query()->active()->ordered()->limit(12)->get(),
            'integrations' => Integration::query()->active()->ordered()->limit(18)->get(),

            /*
             | Screenshots with no file are dropped rather than drawn as a gap:
             | a screenshot section is its screenshots. `imageUrl()` already
             | returns null for a row whose file was swept off disk.
             */
            'showcases' => Showcase::query()->active()->ordered()->limit(6)->get()
                ->filter(fn (Showcase $s) => $s->imageUrl() !== null)
                ->values(),

        ]);
    }

    /* ---------------------------------------------------------------- SEO */

    /**
     * Structured data for the search engines (§19).
     *
     * ---------------------------------------------------------------------
     * Built from the same rows the page draws
     * ---------------------------------------------------------------------
     *
     * Every value here is one a visitor can see somewhere on the page: the
     * questions are the `faqs` table, the phone number is Company Settings.
     * That is not tidiness - Google treats structured data that contradicts
     * the visible page as spam, and a hand written block is a copy nobody
     * updates when the page changes.
     *
     * Two things are described: the business itself, and the questions -
     * which are the ones that actually show up under a listing. The product
     * and its prices went with the plans to Khana Accounts; an offer with no
     * price on the page would be exactly the contradiction above.
     *
     * A section with no rows is left out rather than emitted empty: an
     * `FAQPage` with no questions in it is an invalid graph, and an invalid
     * graph is ignored whole, taking the valid parts with it.
     *
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function structuredData(array $site, Collection $faqs): array
    {
        $graph = [];

        $organisation = array_filter([
            '@type' => 'Organization',
            '@id' => route('landing').'#organisation',
            'name' => $site['name'],
            'url' => route('landing'),
            'logo' => $site['logo'],
            'email' => $site['email'],
            'telephone' => $site['phone'],
            'address' => $site['address'] !== '' ? [
                '@type' => 'PostalAddress',
                'streetAddress' => $site['address'],
                'addressCountry' => 'IN',
            ] : null,
            'sameAs' => array_values($site['social']) ?: null,
        ]);

        $graph[] = $organisation;

        if ($faqs->isNotEmpty()) {
            $graph[] = [
                '@type' => 'FAQPage',
                '@id' => route('landing').'#faq',
                'mainEntity' => $faqs->map(fn (Faq $faq) => [
                    '@type' => 'Question',
                    'name' => $faq->question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq->answer,
                    ],
                ])->values()->all(),
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /*
     | slides() lived here.
     |
     | It read the main_banner layout for the hero carousel. The page no
     | longer draws a banner, so the query is gone rather than left running
     | for rows nobody renders - see the note in the view, which says what to
     | restore if the carousel comes back.
     */

    /* -------------------------------------------------------------- brand */

    /**
     * Everything the chrome needs, read from Company Settings.
     *
     * One array rather than a dozen `Setting::get()` calls scattered through
     * the template: the map is cached, but a view calling it eighteen times is
     * eighteen places to typo a key, and a typo renders as a silently missing
     * phone number.
     *
     * @return array<string, mixed>
     */
    private function site(): array
    {
        $name = Setting::get('company_name', config('app.name'));
        $logo = Setting::get('site_logo');

        return [
            'name' => $name,
            'tagline' => Setting::get('company_title'),
            'logo' => $this->publicUrl($logo),
            'email' => Setting::get('company_email'),
            'phone' => Setting::get('phone'),
            'whatsapp' => Setting::get('whatsapp_no'),

            'address' => collect([
                Setting::get('address_line_1'),
                Setting::get('address_line_2'),
                Setting::get('city'),
                Setting::get('state'),
                Setting::get('zip_code'),
            ])->filter()->implode(', '),

            /*
             | Only the networks somebody filled in. An empty href renders as a
             | link to the current page, which is worse than no icon.
             */
            'social' => collect([
                'Instagram' => Setting::get('instagram_url'),
                'Facebook' => Setting::get('facebook_url'),
                'X' => Setting::get('twitter_url'),
                'LinkedIn' => Setting::get('linkedin_url'),
            ])->filter()->all(),

            'meta_title' => Setting::get('meta_title_home') ?: $name,
            'meta_description' => Setting::get('meta_description_home'),
            'contact_heading' => Setting::get('contact_heading'),
            'contact_description' => Setting::get('contact_description'),
            'demo_video' => Setting::get('hero_demo_video_url'),
        ];
    }

    /**
     * The announcement bar, when there is something to announce.
     *
     * Two sources in order: a slider filed under the `top_strip_bar` layout,
     * then the plain `topbar_offer_text` setting. The slider wins because it
     * can carry a link; the setting is the one-line version for somebody who
     * does not want to build a slide for "Two months free on annual plans".
     */
    private function strip(): ?object
    {
        $slide = Slider::query()
            ->active()
            ->where('layout', 'top_strip_bar')
            ->orderBy('item_no')
            ->first();

        if ($slide) {
            return (object) ['text' => $slide->title, 'url' => $slide->redirect_url];
        }

        $text = Setting::get('topbar_offer_text');

        return filled($text) ? (object) ['text' => $text, 'url' => null] : null;
    }

    /* ----------------------------------------------------------- features */

    /**
     * What the product does, taken from the module catalogue.
     *
     * The same list the sidebar and the shop form are built from -
     * see the class docblock for why it is not a hand-written copy deck.
     *
     * @return Collection<int, array<string, string>>
     */
    private function modules(): Collection
    {
        return collect(Modules::catalogue())
            ->map(fn (array $m, string $key) => [
                'key' => $key,
                'label' => $m['label'],
                'blurb' => $m['blurb'] ?? '',
                'icon' => $m['icon'] ?? 'grid',
            ])
            ->values();
    }

    /**
     * How the thing actually works, in the order it happens.
     *
     * Hard-coded, and the one place on this page that is. These four steps are
     * the product's own flow - QR to kitchen to bill - not a preference: they
     * are fixed by how the code works, and a settings field that let somebody
     * reorder them would be a field that lets them describe software that does
     * not exist.
     *
     * @return array<int, array<string, string>>
     */
    private function steps(): array
    {
        return [
            [
                'n' => '01',
                'title' => 'Guest scans the table code',
                'body' => 'Every table gets its own QR sticker. No app to install — the '
                    .'menu opens in the phone\'s browser, with your branding on it.',
            ],
            [
                'n' => '02',
                'title' => 'They order from their seat',
                'body' => 'Sizes, add-ons and special instructions. Sold-out dishes '
                    .'disappear the moment you tap the toggle.',
            ],
            [
                'n' => '03',
                'title' => 'The kitchen gets it instantly',
                'body' => 'Each line lands on the station that cooks it — tandoor, bar, '
                    .'bakery — with a timer running from the moment it arrived.',
            ],
            [
                'n' => '04',
                'title' => 'The counter settles the table',
                'body' => 'One bill or split five ways, any payment method, GST worked '
                    .'out. Stock and reports move from the same sale.',
            ],
        ];
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * A stored path turned into a URL, or null when the file is gone.
     *
     * The same tolerance the models apply to their own media: a logo swept off
     * disk shows as the company's name in text rather than a broken image.
     */
    private function publicUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }
}
