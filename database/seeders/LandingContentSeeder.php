<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\OutletType;
use App\Models\Service;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Starter content for the product's own landing page.
 *
 * The FAQs, services and outlet types a restaurant owner reads before signing
 * up - written about this software, so a fresh install's landing page is
 * about restaurants rather than about whatever the demo data was last written
 * for. All of it is ordinary rows Super Admin edits or switches off under
 * Content.
 *
 * Idempotent: keyed on the question, slug and name, so running it again
 * updates the wording rather than adding a second copy.
 *
 * Testimonials and integrations are deliberately not seeded. A quote on a
 * public page reads as a real customer's, and a logo as a real partnership;
 * neither is something a seeder can honestly make up.
 */
class LandingContentSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public const FAQS = [
        ['Do my guests need to install an app?', 'No. They scan the QR code on their table and the menu opens in the phone\'s browser, with your name and logo on it.', 'Ordering'],
        ['What hardware do I need?', 'Any laptop, desktop or tablet with a browser runs the counter. The kitchen display is any screen with a browser, and bills print to the thermal printer at your counter.', 'Setup'],
        ['Does it handle GST?', 'Yes. Every bill carries your GSTIN, with CGST and SGST worked out on each line, and the reports show the tax you owe.', 'Billing'],
        ['Can I run more than one outlet?', 'Yes. Your plan sets how many branches you can add; switch between them from the top bar, and stock and reports are kept per outlet.', 'Plans'],
        ['How will the kitchen know about a new order?', 'It lands on the kitchen display straight away, with an alert that plays a sound. Each person can choose which alerts they hear.', 'Kitchen'],
        ['Can my accountant get the numbers?', 'Yes. Sales export to Tally from the reports screen, so nobody re-types a day\'s bills.', 'Reports'],
        ['What happens when the free trial ends?', 'We email you two days before, a day before and on the last day. Pick a plan to carry on; your menu, tables and bills stay exactly as you left them.', 'Plans'],
        ['Is my data kept separate from other restaurants?', 'Yes. Every restaurant\'s menu, orders, customers and reports belong to its own account, and no other account can see them.', 'Security'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public const SERVICES = [
        ['Menu Setup', 'Send us your menu as a PDF or a photo and we load the dishes, prices, taxes and categories.', 'file'],
        ['Staff Training', 'A walk-through for the counter, the floor and the kitchen, on your own menu.', 'users'],
        ['QR Table Stickers', 'A printable QR code for every table, ready to stick down on day one.', 'scan'],
        ['Printer & Hardware Setup', 'Your billing printer, kitchen screen and barcode scanner connected and tested.', 'tool'],
        ['Data Import', 'Customers, items and opening stock brought across from your old system.', 'download'],
        ['Phone & WhatsApp Support', 'A real person to call when something on the floor does not look right.', 'phone'],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public const OUTLET_TYPES = [
        ['Fine Dining', 'Table service, courses and split bills.', 'star'],
        ['Cafés', 'Fast counter billing with QR ordering at the tables.', 'heart'],
        ['Quick Service', 'Takeaway and token orders at lunch-rush speed.', 'zap'],
        ['Cloud Kitchens', 'Delivery orders and kitchen tickets, no dining room.', 'truck'],
        ['Bars & Lounges', 'Running tabs, a bar station and late-night day close.', 'globe'],
        ['Bakeries & Sweet Shops', 'Barcode labels, weighed items and counter sales.', 'gift'],
    ];

    public function run(): void
    {
        foreach (self::FAQS as $i => [$question, $answer, $category]) {
            Faq::query()->updateOrCreate(
                ['question' => $question],
                ['answer' => $answer, 'category' => $category, 'is_active' => true, 'sort_order' => $i],
            );
        }

        foreach (self::SERVICES as $i => [$name, $description, $icon]) {
            Service::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'short_description' => $description,
                    'full_description' => $description,
                    'icon' => $icon,
                    'is_active' => true,
                    'sort_order' => $i,
                ],
            );
        }

        foreach (self::OUTLET_TYPES as $i => [$name, $blurb, $icon]) {
            OutletType::query()->updateOrCreate(
                ['name' => $name],
                ['blurb' => $blurb, 'icon' => $icon, 'is_active' => true, 'sort_order' => $i],
            );
        }
    }
}
