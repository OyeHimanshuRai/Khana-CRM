<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\Service;
use App\Models\Slider;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;

/**
 * The public landing page's copy: FAQs, services and sliders.
 *
 * All of it is plain rows - there is no service to route through, because
 * none of it moves stock or money. What it does need is to read like a real
 * restaurant platform wrote it: an FAQ list of "Question 1..20" tells you the
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

        $this->faqs();
        $this->services();
        $this->sliders();
    }

    private function faqs(): void
    {
        if ($this->alreadySeeded('FAQs', Faq::query()->count())) {
            return;
        }

        /*
         | A restaurant's own questions.
         |
         | These were an agricultural dealer's - deliveries to a village,
         | whether a pesticide was genuine, which seed suits which crop -
         | sitting under a heading that says "Asked often" on a restaurant
         | POS site. Demo content is the first thing a prospective customer
         | reads, and content from somebody else's trade reads as a broken
         | product rather than as a placeholder.
         */
        $faqs = [
            ['How long does it take to set up?', 'A single outlet is usually billing the same day. We import your menu from a spreadsheet, print the table QR codes and train the counter in an afternoon.', 'Getting started'],
            ['Can I import my existing menu?', 'Yes. Send a spreadsheet of dishes, sizes and prices and we will load it, including add-ons and variants.', 'Getting started'],
            ['Does it work if the internet goes down?', 'The till keeps billing and the kitchen display keeps running on your own wifi. Anything that needs the internet queues and catches up when the line is back.', 'Reliability'],
            ['Do I need new hardware?', 'Usually not. It runs in a browser on the machines you already have, and works with the common thermal printers and wireless scanners.', 'Hardware'],
            ['Which printers are supported?', 'Any ESC/POS thermal printer, including the Epson TM-T82 and the usual 58mm and 80mm counter models. KOTs can go to a different printer from bills.', 'Hardware'],
            ['Is the bill GST compliant?', 'Yes. Every sale raises a GST invoice with the tax split shown, HSN codes on the lines, and CGST/SGST or IGST decided from the two addresses.', 'Billing'],
            ['Can guests order from their phone?', 'Each table gets its own QR code. The menu opens in the browser with no app to install, and the order lands on the kitchen display straight away.', 'Dine-in'],
            ['Can one table split the bill?', 'Yes, as many ways as the table asks for, with any mix of payment methods on each part.', 'Billing'],
            ['How are kitchen tickets routed?', 'Each dish is assigned to a station — tandoor, bar, bakery — and its lines appear only on that station screen, with a timer from the moment the order arrived.', 'Kitchen'],
            ['Does it track ingredients?', 'Set a recipe against a dish and its ingredients come off the shelf when the kitchen cooks it, not when the bill is raised. A dish itself is made to order and carries no stock of its own.', 'Inventory'],
            ['Can I run more than one outlet?', 'Yes. Each outlet has its own subscription, staff, menu prices and reports, and you switch between them from the header.', 'Outlets'],
            ['What happens if one outlet\'s subscription lapses?', 'Only that outlet stops. Your other branches keep trading and nobody is signed out.', 'Outlets'],
            ['Can staff be limited to one branch?', 'Yes. Roles are per branch, and somebody can be a manager at one outlet and a cashier at another.', 'Staff'],
            ['Can a cashier give a discount?', 'Only with the discount right. Without it the price boxes are read only and the sale is refused server-side, so it cannot be worked around.', 'Staff'],
            ['How does day close work?', 'Open the till with a float, and at the end of the day count the drawer. The expected figure comes from the payments ledger, and any variance is flagged for approval.', 'Billing'],
            ['Do you integrate with Swiggy and Zomato?', 'Aggregator orders come in through the API and land on the same kitchen display as your own, so the pass has one queue.', 'Integrations'],
            ['Can I take payment by UPI and card?', 'Cash, UPI, card, bank transfer and cheque, and a single bill can be settled with more than one of them.', 'Billing'],
            ['What reports do I get?', 'Sales by day, dish, category and staff member; stock movement and valuation; purchase and supplier ledgers; and every one of them exports.', 'Reports'],
            ['Is my data mine if I leave?', 'Yes. Every screen exports, and we will hand over a full dump of your data on request.', 'General'],
            ['Is there a free trial?', 'Fourteen days on the Starter and Restaurant plans, with your own menu loaded, and nothing to cancel if you walk away.', 'Getting started'],
        ];

        foreach ($faqs as $index => [$question, $answer, $category]) {
            Faq::query()->create([
                'question' => $question,
                'answer' => $answer,
                'category' => $category,
                'is_active' => $index < 19,
                'sort_order' => $index,
            ]);
        }

        $this->say(sprintf('%d FAQs.', count($faqs)));
    }

    private function services(): void
    {
        if ($this->alreadySeeded('Services', Service::query()->count())) {
            return;
        }

        /*
         | What a restaurant actually buys from us besides the software -
         | setup, training and the things that are not a screen. Replaces a
         | farm supplier's list: soil testing, sprayer repair and drip layout
         | planning, which read as somebody else's business.
         */
        $services = [
            ['Menu Setup', 'We load your dishes, sizes, add-ons and prices from a spreadsheet.', 0],
            ['Onsite Training', 'A half day at your counter with the cashiers and the kitchen.', 2500],
            ['Table QR Printing', 'Printed and laminated QR codes for every table, delivered.', 900],
            ['Printer Installation', 'Bill and KOT printers configured and routed to the right stations.', 1200],
            ['Data Migration', 'Customers, suppliers and opening stock brought over from your old system.', 3500],
            ['Recipe Costing', 'Ingredients mapped to dishes so food cost comes out of the till.', 2000],
            ['Floor Plan Setup', 'Your dining areas and tables drawn to match the room.', 0],
            ['Menu Photography', 'Dish photos for the QR menu and the online store.', 4000],
            ['GST Filing Support', 'Monthly returns prepared from the invoices already in the system.', 1500],
            ['Priority Support', 'A direct line during service hours, ahead of the queue.', 999],
            ['Custom Reports', 'A report built to the question you actually ask.', 2500],
            ['Hardware Sourcing', 'Printers, scanners and kitchen screens supplied and tested.', 0],
            ['Multi-Outlet Rollout', 'A second and third branch set up to match the first.', 5000],
            ['Aggregator Integration', 'Swiggy and Zomato orders routed into your kitchen display.', 1800],
            ['WhatsApp Setup', 'Bills and booking confirmations sent from your own number.', 1200],
            ['Loyalty Programme Design', 'Points, tiers and rewards set up for your regulars.', 1500],
            ['Stock Take Assistance', 'A guided first count so opening stock is right.', 2000],
            ['Annual Health Check', 'A yearly review of settings, permissions and unused modules.', 0],
            ['Menu Engineering Review', 'Which dishes earn their place, read off your own sales.', 3000],
            ['After-Sale Support', 'We take hardware and integration faults up with the vendor for you.', 0],
        ];

        foreach ($services as $index => [$name, $description, $price]) {
            Service::query()->create([
                'name' => $name,
                'slug' => Service::uniqueSlug($name),
                'short_description' => $description,
                'full_description' => $description.' Ask at the counter for details.',
                'icon' => $this->pick(['leaf', 'truck', 'settings', 'shield', 'package']),
                'price' => $price ?: null,
                'price_from' => $price > 0 && $this->chance(40),
                'is_active' => $index < 19,
                'sort_order' => $index,
            ]);
        }

        $this->say(sprintf('%d services.', count($services)));
    }

    private function sliders(): void
    {
        if ($this->alreadySeeded('Sliders', Slider::query()->count())) {
            return;
        }

        $layouts = array_keys(config('slider_layouts', ['main_banner' => 'Main Banner']));

        $titles = [
            'The new menu is here', 'Chef specials this week', 'Family packs from ₹599',
            'Free delivery above ₹500', 'Fresh from the tandoor', 'Biryani festival on now',
            'Weekend brunch is back', 'Order from your table', 'Desserts worth the room',
            'Combo meals for two', 'Party trays for gatherings', 'Happy hours, 4 to 7',
            'Jain menu available', 'Bakery counter open', 'Last orders at 11',
            'Loyalty points on every bill', 'Book a table tonight', 'New arrivals this week',
            'Corporate lunch rates', 'Customer day announcement',
        ];

        foreach ($titles as $index => $title) {
            Slider::query()->create([
                'title' => $title,
                'description' => $title.' — ask at the counter.',
                'item_no' => $index + 1,
                'layout' => $layouts[$index % count($layouts)],
                'redirect_url' => $this->chance(60) ? '/collections' : null,
                'is_active' => $index < 17,
            ]);
        }

        $this->say(sprintf('%d sliders.', count($titles)));
    }

}
