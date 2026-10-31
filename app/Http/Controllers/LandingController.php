<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\Integration;
use App\Models\OutletType;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Showcase;
use App\Models\Slider;
use App\Models\Testimonial;
use App\Models\Service;
use App\Models\Setting;
use App\Support\CompanySettings;
use App\Support\Modules;
use App\Support\SiteBrand;
use Illuminate\Support\Collection;
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
 * up. It answers four questions, in this order, because that is the order they
 * are actually asked in:
 *
 *   1. What kind of product is this?     the hero
 *   2. What can it do?                   the modules
 *   3. What else do you do for me?       services
 *   4. What does it cost?                plans
 *
 * A guest's *restaurant* menu is a different thing entirely and lives behind
 * the table QR - see the `t/` routes.
 *
 * ---------------------------------------------------------------------------
 * The feature list is the real one
 * ---------------------------------------------------------------------------
 *
 * `Modules::catalogue()` is not a marketing copy deck. It is the same list the
 * sidebar filters against, that a shop switches lines of business on with, and
 * that a Plan grants. So a feature advertised here is a feature that exists,
 * and one that is switched off platform-wide stops being advertised on the
 * same deploy. A hand-written list on a marketing page is a promise nobody
 * updates when the code changes.
 *
 * The same goes for pricing: the cards come from `plans`, the table Super Admin
 * edits under Settings > Plans. There is no second copy of a price.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing.index', $this->pageData());
    }

    public function section(string $section): View
    {
        abort_unless(in_array($section, ['how-it-works', 'features', 'billing', 'faq'], true), 404);

        return view('landing.section', $this->pageData() + ['section' => $section]);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageData(): array
    {
        return [
            'site' => $this->site(),
            'strip' => $this->strip(),
            'modules' => $this->modules(),
            'services' => Service::query()->active()->orderBy('sort_order')->limit(6)->get(),
            'plans' => $this->plans(),
            'faqs' => Faq::query()->active()->orderBy('sort_order')->limit(8)->get(),
            'steps' => $this->steps(),
            'images' => $this->images(),
            'backdrops' => $this->backdrops(),
            'footerPages' => Page::query()->footer()->get(['id', 'title', 'slug']),

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

        ];
    }

    /* ------------------------------------------------------------- images */

    /**
     * The landing page's pictures: Super Admin's upload, else the built-in one.
     *
     * Each slot is a file field in the "landing" section of
     * config/company_settings.php. The fallback is an illustration shipped
     * in public/assets/img/landing, so a fresh install looks finished and a
     * removed upload never leaves a hole.
     *
     * @return array<string, string>
     */
    private function images(): array
    {
        $defaults = [
            'hero' => ['landing_hero_image', 'hero.svg'],
            'pos' => ['landing_pos_image', 'shot-pos.svg'],
            'billing' => ['landing_billing_image', 'step-bill.svg'],
            'reports' => ['landing_reports_image', 'shot-reports.svg'],
            'scan' => ['landing_step_scan_image', 'step-scan.svg'],
            'order' => ['landing_step_order_image', 'step-order.svg'],
            'kitchen' => ['landing_step_kitchen_image', 'step-kitchen.svg'],
            'bill' => ['landing_step_bill_image', 'step-bill.svg'],
        ];

        return array_map(
            fn (array $slot) => CompanySettings::fileUrl($slot[0]) ?? asset('assets/img/landing/'.$slot[1]),
            $defaults,
        );
    }

    /**
     * The hero's rotating background, from the Landing Hero Background layout.
     *
     * The Sliders module stores the layout as `main_banner`. Only active
     * slides with a desktop picture count - a slide with no picture has
     * nothing to put behind the headline - and the phone gets the portrait
     * crop when one was uploaded. Five at most: past that, nobody waits long
     * enough to see the sixth.
     *
     * @return Collection<int, array{desktop: string, mobile: string}>
     */
    private function backdrops(): Collection
    {
        return Slider::query()
            ->active()
            ->where('layout', 'main_banner')
            ->whereNotNull('desktop_image_path')
            ->orderBy('item_no')
            ->limit(5)
            ->get()
            ->map(function (Slider $slide) {
                $desktop = $slide->mediaUrl('desktop_image_path');

                return $desktop === null ? null : [
                    'desktop' => $desktop,
                    'mobile' => $slide->mediaUrl('mobile_image_path') ?? $desktop,
                ];
            })
            ->filter()
            ->values();
    }

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
        return SiteBrand::get();
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
     * The same list the sidebar, the shop form and every Plan are built from -
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
                'image' => 'scan',
                'title' => 'Guest scans the QR on the table',
                'body' => 'Every table gets its own QR sticker. No app to install — the '
                    .'menu opens in the phone\'s browser, with your branding on it.',
            ],
            [
                'n' => '02',
                'image' => 'order',
                'title' => 'They order from their seat',
                'body' => 'Sizes, add-ons and special instructions. Sold-out dishes '
                    .'disappear the moment you tap the toggle.',
            ],
            [
                'n' => '03',
                'image' => 'kitchen',
                'title' => 'The kitchen gets it instantly',
                'body' => 'Each line lands on the station that cooks it — tandoor, bar, '
                    .'bakery — with a timer running from the moment it arrived.',
            ],
            [
                'n' => '04',
                'image' => 'bill',
                'title' => 'The counter settles the table',
                'body' => 'One bill or split five ways, any payment method, GST worked '
                    .'out. Stock and reports move from the same sale.',
            ],
        ];
    }

    /* ------------------------------------------------------------ pricing */

    /**
     * The plans on sale.
     *
     * Straight from the `plans` table, which is what Super Admin edits under
     * Settings > Plans, so a price change on that screen is a price change
     * here. Only active plans - an archived plan is one that is no longer sold
     * but that existing subscribers are still on.
     *
     * The "most popular" flag is the middle plan by sort order rather than a
     * column, because there is no column for it and inventing one to decorate
     * a landing page would be a schema change for a border colour. With two
     * plans it marks the second; with one it marks nothing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function plans(): Collection
    {
        $plans = Plan::query()->onSale()->orderBy('sort_order')->orderBy('monthly_price')->get();

        $highlight = $plans->count() >= 2 ? (int) floor(($plans->count() - 1) / 2) : -1;

        return $plans->values()->map(fn (Plan $plan, int $i) => [
            'plan' => $plan,
            'featured' => $i === $highlight,
            'monthly' => $plan->priceFor(Plan::MONTHLY),
            'yearly' => $plan->priceFor(Plan::YEARLY),
            /*
             | What a year actually saves, in months rather than a percentage -
             | "two months free" is a thing somebody can picture, and 16.7% is
             | not. Null when the yearly price is simply twelve monthlies.
             */
            'months_free' => $this->monthsFree($plan),
            'limits' => $plan->limitLines(),
            'modules' => collect($plan->moduleKeys())
                ->map(fn (string $k) => Modules::catalogue()[$k]['label'] ?? $k)
                ->all(),
        ]);
    }

    private function monthsFree(Plan $plan): ?int
    {
        $monthly = (float) $plan->monthly_price;

        if ($monthly <= 0) {
            return null;
        }

        $saved = ($monthly * 12) - $plan->priceFor(Plan::YEARLY);
        $months = (int) round($saved / $monthly);

        return $months > 0 ? $months : null;
    }

}
