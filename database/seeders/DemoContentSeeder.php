<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\Event;
use App\Models\InstagramPost;
use App\Models\Reel;
use App\Models\Slider;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The storefront's editorial side, and the mailing list.
 *
 * All of it is plain rows - there is no service to route through, because
 * none of it moves stock or money. What it does need is to read like a real
 * agri retailer wrote it: an FAQ list of "Question 1..20" tells you the
 * screen renders, and nothing about whether the screen is any good.
 *
 * No image files are attached. Every one of these tables stores a path to
 * the public disk, and pointing twenty rows at images that were never
 * uploaded would render twenty broken thumbnails - worse than the empty
 * state each screen already has.
 */
class DemoContentSeeder extends Seeder
{
    use SeedsDemoData;

    public function run(): void
    {
        $this->seedRandom(8);

        $this->blogs();
        // The landing page's FAQs and services, written about restaurants.
        $this->call(LandingContentSeeder::class);
        $this->events();
        $this->sliders();
        $this->reels();
        $this->instagramPosts();
    }

    private function blogs(): void
    {
        if ($this->alreadySeeded('Blog posts', Blog::query()->count())) {
            return;
        }

        $posts = [
            ['When to sow wheat in Rajasthan', 'Crop Guides'],
            ['Choosing the right mustard variety', 'Crop Guides'],
            ['Controlling whitefly in cotton', 'Pest Control'],
            ['Reading a fertiliser bag: what 19:19:19 means', 'Fertilisers'],
            ['Soil testing before the rabi season', 'Soil Health'],
            ['Drip irrigation on a one-bigha plot', 'Irrigation'],
            ['Storing seed through the summer', 'Storage'],
            ['Spraying safely: mask, gloves and timing', 'Safety'],
            ['Identifying late blight in potato', 'Pest Control'],
            ['Bio pesticides versus chemical: what actually works', 'Pest Control'],
            ['Micronutrients and why zinc matters in paddy', 'Fertilisers'],
            ['Getting the most from a knapsack sprayer', 'Equipment'],
            ['Weed control before sowing groundnut', 'Crop Guides'],
            ['Vermicompost at home for a small farm', 'Organic'],
            ['Understanding GST on farm inputs', 'Business'],
            ['Buying seed: how to check the lot number', 'Buying Guides'],
            ['Managing stem borer in paddy', 'Pest Control'],
            ['A calendar for the kharif season', 'Crop Guides'],
            ['Water soluble fertiliser through drip', 'Irrigation'],
            ['What to do when a crop shows yellowing', 'Crop Guides'],
        ];

        $user = auth()->user();

        foreach ($posts as $index => [$title, $category]) {
            $status = match (true) {
                $index >= 18 => Blog::DRAFT,
                $index === 17 => Blog::INACTIVE,
                default => Blog::PUBLISHED,
            };

            Blog::query()->create([
                'title' => $title,
                'slug' => Blog::uniqueSlug($title),
                'short_description' => $title.' — a short practical guide for the season.',
                'content' => $this->article($title),
                'author_id' => $user?->id,
                'author_name' => $user?->name ?? 'Counter Desk',
                'category' => $category,
                'tags' => $this->pick(['rabi,wheat', 'kharif,cotton', 'pest,spray', 'fertiliser,soil', 'irrigation']),
                'published_at' => $status === Blog::PUBLISHED ? $this->recentDate(180) : null,
                'seo_title' => $title,
                'seo_description' => Str::limit($title.' — practical advice from the counter.', 150),
                'status' => $status,
                'is_featured' => $index < 4,
            ]);
        }

        $this->say(sprintf('%d blog posts.', count($posts)));
    }

    private function article(string $title): string
    {
        return implode("\n\n", [
            '<p>'.$title.' is one of the questions we are asked most often at the counter, '
                .'usually a week after it would have been useful. This note is what we tell people.</p>',
            '<h3>The short answer</h3>',
            '<p>Timing matters more than product choice. Get the timing wrong and the best input on '
                .'the shelf will not save the crop; get it right and an ordinary one will do.</p>',
            '<h3>What to watch for</h3>',
            '<ul><li>Check the field in the cool hours, not the middle of the day.</li>'
                .'<li>Look at the underside of the leaf, which is where trouble starts.</li>'
                .'<li>Treat a patch before it becomes a field.</li></ul>',
            '<p>Bring a leaf or a photograph to the counter and we will tell you what we think it is. '
                .'There is no charge for a look.</p>',
        ]);
    }

    private function events(): void
    {
        if ($this->alreadySeeded('Events', Event::query()->count())) {
            return;
        }

        $events = [
            'Kharif Farmer Meet', 'Rabi Sowing Demonstration', 'Cotton Field Day',
            'Soil Health Camp', 'Drip Irrigation Workshop', 'Seed Treatment Demo',
            'Pest Identification Camp', 'Organic Farming Session', 'Sprayer Safety Training',
            'Krishi Mela Stall', 'Wheat Variety Showcase', 'Mustard Growers Meeting',
            'Vegetable Growers Meet', 'Micronutrient Awareness Camp', 'Bio Pesticide Demo',
            'Farm Equipment Expo', 'Government Scheme Camp', 'Water Testing Camp',
            'Harvest Season Meet', 'Annual Customer Day',
        ];

        foreach ($events as $index => $title) {
            // A mix of past and upcoming, so the events screen is not a
            // history page or a wish list but both.
            $from = $index < 12
                ? Carbon::today()->subDays($this->between(10, 200))
                : Carbon::today()->addDays($this->between(5, 120));

            Event::query()->create([
                'title' => $title,
                'name' => $title,
                'timing' => $this->pick(['10:00 AM – 1:00 PM', '9:00 AM – 12:00 PM', '4:00 PM – 7:00 PM']),
                'from_date' => $from,
                'to_date' => $from->copy()->addDays($this->pick([0, 0, 1, 2])),
                'booth_no' => $this->chance(40) ? 'Stall '.$this->between(1, 40) : null,
                'is_active' => $index < 19,
            ]);
        }

        $this->say(sprintf('%d events.', count($events)));
    }

    private function sliders(): void
    {
        if ($this->alreadySeeded('Sliders', Slider::query()->count())) {
            return;
        }

        $layouts = array_keys(config('slider_layouts', ['main_banner' => 'Main Banner']));

        /*
         | Index 7 and 15 land on top_strip_bar, the landing page's announcement
         | strip, so those two read as one. None carries a picture, so none is
         | drawn behind the hero - see LandingController::backdrops().
         */
        $titles = [
            'QR ordering for every table', 'Kitchen display on any screen', 'GST billing in seconds',
            'Live order alerts with sound', 'Stock that moves with every sale', 'Tally export for your accountant',
            'Split payments at the counter', 'Start a free trial — no card needed', 'Day close in one click',
            'Reports before you lock up', 'Barcode labels for the bakery', 'Multi-outlet, one login',
            'Table status at a glance', 'Takeaway and delivery orders', 'Customer ledger and credit',
            'New: guest order tracking', 'Purchase orders and suppliers', 'Menu changes go live instantly',
            'Sold-out toggles for the menu', 'Restaurant setup help',
        ];

        foreach ($titles as $index => $title) {
            Slider::query()->create([
                'title' => $title,
                'description' => $title.'.',
                'item_no' => $index + 1,
                'layout' => $layouts[$index % count($layouts)],
                // No link: a root-relative one breaks on an install in a sub-folder.
                'redirect_url' => null,
                'is_active' => $index < 17,
            ]);
        }

        $this->say(sprintf('%d sliders.', count($titles)));
    }

    private function reels(): void
    {
        if ($this->alreadySeeded('Reels', Reel::query()->count())) {
            return;
        }

        $titles = [
            'How to mix a spray correctly', 'Checking seed for germination',
            'Reading a fertiliser label', 'Whitefly on cotton — what it looks like',
            'Cleaning a knapsack sprayer', 'Treating seed before sowing',
            'Late blight on potato', 'Making vermicompost at home',
            'Setting up a drip line', 'Zinc deficiency in paddy',
            'Storing chemicals safely', 'Weeds before groundnut sowing',
            'Stem borer damage in paddy', 'Choosing a mustard variety',
            'Soil sampling the right way', 'Nozzle types explained',
            'Bio pesticide application', 'Wheat sowing depth',
            'Reading a batch number', 'Counter tour',
        ];

        foreach ($titles as $index => $title) {
            Reel::query()->create([
                'title' => $title,
                'description' => $title.' — a short clip from the counter.',
                'reel_url' => 'https://www.instagram.com/reel/DEMO'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT).'/',
                'reel_id' => 'DEMO'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'sort_order' => $index,
                'published_at' => $this->recentDate(150),
                'is_active' => $index < 18,
            ]);
        }

        $this->say(sprintf('%d reels.', count($titles)));
    }

    private function instagramPosts(): void
    {
        if ($this->alreadySeeded('Instagram posts', InstagramPost::query()->count())) {
            return;
        }

        $titles = [
            'New seed stock in', 'Farmer meet this Sunday', 'Spray safely this season',
            'Fertiliser arrived today', 'Soil testing camp photos', 'Cotton field visit',
            'Bio inputs on the shelf', 'Counter team at work', 'Drip demo in the field',
            'Wheat variety on display', 'Customer day highlights', 'Sprayer servicing',
            'Organic corner restocked', 'Mandi rate update', 'Harvest season greetings',
            'New micronutrient range', 'Safety gear in stock', 'Delivery round today',
            'Krishi mela stall', 'Diwali greetings from the counter',
        ];

        foreach ($titles as $index => $title) {
            InstagramPost::query()->create([
                'title' => $title,
                'description' => $title.'.',
                'post_url' => 'https://www.instagram.com/p/DEMO'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT).'/',
                'post_id' => 'DEMO'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'sort_order' => $index,
                'is_active' => $index < 18,
            ]);
        }

        $this->say(sprintf('%d Instagram posts.', count($titles)));
    }
}
