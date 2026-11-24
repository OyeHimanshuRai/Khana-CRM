<?php

namespace App\Support;

use App\Models\Faq;

/**
 * The questions a restaurant owner asks before signing up, answered once.
 *
 * Added only when the question is not already there, and never overwritten:
 * from then on Super Admin edits, reorders or switches them off under
 * Content > FAQs.
 */
final class DefaultFaqs
{
    /** @var array<int, array{0: string, 1: string, 2: string}> question, answer, category */
    public const ALL = [
        // ---- Getting started
        ['What is RestroOne?', 'RestroOne is restaurant software that runs in your browser: table and counter billing, QR ordering, a kitchen display, stock, purchasing, customer records, GST invoices and reports - all in one place, with nothing to install.', 'Getting started'],
        ['Who is it for?', 'Restaurants, cafés, quick-service counters, cloud kitchens, bars, bakeries and sweet shops - one outlet or many.', 'Getting started'],
        ['What hardware do I need?', 'Any laptop, desktop or tablet with a browser runs the counter. The kitchen display is any screen with a browser, and bills print to the thermal printer at your counter.', 'Getting started'],
        ['How do I get started?', 'Register on the website, confirm your email with the code we send, and sign in. Your restaurant is created with its first outlet; add your tables and menu and you can take the first order the same day.', 'Getting started'],
        ['Can I bring my menu in from a spreadsheet?', 'Yes. Download the template from the Products screen, fill in one dish per row (only the name is required), save it as CSV and upload it. A preview shows what would be added before anything changes.', 'Getting started'],
        ['Do I need to install an app?', 'No. RestroOne opens in the browser on your phone, tablet or computer, and your guests do not need an app either.', 'Getting started'],

        // ---- Plans and trial
        ['Is there a free trial?', 'Yes. A plan with a trial starts it the moment you register, and no card is needed. Nothing is charged automatically at the end.', 'Plans and payment'],
        ['What happens when the free trial ends?', 'We email you two days before, a day before and on the last day. Pick a plan to carry on; your menu, tables and bills are kept while you decide.', 'Plans and payment'],
        ['How do I pay for a plan?', 'Choose a plan on the Subscription page of your account. Plans are paid in advance for a month or a year, and a GST invoice is issued for every payment.', 'Plans and payment'],
        ['Is GST added to the plan price?', 'Yes, where it applies. The invoice shows the amount and the GST separately.', 'Plans and payment'],
        ['Can I change my plan later?', 'Yes. You can move to a bigger plan when you need more outlets or features; the unused part of your current plan is credited toward it.', 'Plans and payment'],
        ['Can I cancel? Do I get a refund?', 'You can stop at any time; plans do not renew by themselves. Refunds are explained in our Refund and Cancellation Policy, linked in the footer.', 'Plans and payment'],

        // ---- Ordering
        ['Do my guests need to install an app?', 'No. They scan the QR code on their table and the menu opens in the phone\'s browser, with your name and logo on it.', 'Ordering'],
        ['Can I take dine-in, takeaway and delivery orders?', 'Yes, all three from the same screen. A dish can have its own price for each if you want one.', 'Ordering'],
        ['Can I hold an order and come back to it?', 'Yes. A sale can be parked and picked up again later, and a table\'s bill stays open until the guests are ready to pay.', 'Ordering'],
        ['Can I take table reservations?', 'Yes. Bookings are kept on the Reservations screen with the time, party size and table, so the floor knows what is coming.', 'Ordering'],

        // ---- Billing and GST
        ['Does it handle GST?', 'Yes. Every bill carries your GSTIN, with CGST and SGST worked out on each line, and the reports show the tax you owe.', 'Billing'],
        ['Which payments can I record?', 'Cash, UPI and card, including a bill paid part in one way and part in another.', 'Billing'],
        ['Can I print bills with my logo?', 'Yes. Invoices print with your logo, GSTIN and address, on a thermal printer or a normal one.', 'Billing'],
        ['How does closing the day work?', 'Day Close counts the cash against what the system expects, and a report of the day is kept for you and your accountant.', 'Billing'],

        // ---- Kitchen
        ['How will the kitchen know about a new order?', 'It lands on the kitchen display straight away, with an alert that plays a sound. Each person can choose which alerts they hear.', 'Kitchen'],
        ['Can different dishes go to different counters?', 'Yes. Set up kitchen stations - tandoor, bar, dessert - and each dish goes to the station that makes it.', 'Kitchen'],

        // ---- Stock and purchasing
        ['Can it track my stock?', 'Yes. Stock moves through purchases, adjustments and wastage, and a recipe takes the ingredients off stock as a dish is made. Low-stock and expiry reports tell you what to reorder.', 'Stock and purchasing'],
        ['Can I order from my suppliers?', 'Yes. Raise a purchase order, receive the goods against it, and the stock and the supplier\'s balance update together.', 'Stock and purchasing'],

        // ---- Team and outlets
        ['Can I run more than one outlet?', 'Yes. Your plan sets how many branches you can add; switch between them from the top bar, and stock and reports are kept per outlet.', 'Team and outlets'],
        ['Can my staff have their own logins?', 'Yes. Add each person with a role - owner, manager, cashier, waiter, kitchen - and they see only what that role allows. Every sign-in is recorded.', 'Team and outlets'],

        // ---- Reports
        ['Can my accountant get the numbers?', 'Yes. Sales export to Tally from the reports screen, so nobody re-types a day\'s bills. Reports can also be downloaded for a spreadsheet.', 'Reports'],
        ['What reports do I get?', 'Sales by day, dish, category and payment type, GST, stock, expiry, wastage and customers - for one outlet or all of them.', 'Reports'],

        // ---- Online store
        ['Do I get an online store?', 'Yes. List the dishes you want on your own web address, and customers can look at the menu and order from their phone.', 'Online store'],

        // ---- Data and security
        ['Is my data kept separate from other restaurants?', 'Yes. Every restaurant\'s menu, orders, customers and reports belong to its own account, and no other account can see them.', 'Data and security'],
        ['Is my data safe?', 'Everything travels over HTTPS, passwords are stored only as one-way hashes, and access is limited by role. Our Privacy Policy, linked in the footer, explains what we keep and why.', 'Data and security'],
        ['Can I take my data with me?', 'Yes. Your menu, customers, sales and stock can be exported at any time, and for 30 days after an account ends you can ask us for a full export.', 'Data and security'],

        // ---- Support
        ['How do I get help?', 'Open the Support section in your account and we will reply there, or write to us at the email address in the footer. You can also use Contact support on our website and our team will reply by email.', 'Support'],
    ];

    /** @return int how many questions were added */
    public static function publish(): int
    {
        $made = 0;

        foreach (self::ALL as $i => [$question, $answer, $category]) {
            $faq = Faq::query()->firstOrCreate(
                ['question' => $question],
                ['answer' => $answer, 'category' => $category, 'is_active' => true, 'sort_order' => $i],
            );

            $made += (int) $faq->wasRecentlyCreated;
        }

        return $made;
    }
}
