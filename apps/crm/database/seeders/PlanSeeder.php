<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The starting price list (SRS 2, 21).
 *
 * Three plans that describe the three restaurants this product is actually
 * sold to: a single counter, a restaurant with a kitchen and a floor, and a
 * chain. They are a starting point to edit, not a scheme to live with -
 * which is why the codes are plain and the prices are round.
 *
 * ---------------------------------------------------------------------------
 * No plan is given to anybody
 * ---------------------------------------------------------------------------
 *
 * Seeding the catalogue does not subscribe a single tenant. An install that
 * is one restaurant rather than a SaaS should see three unused rows on a
 * screen it never opens, and carry on with no limits at all - which is
 * exactly what happens, because every ceiling in PlanAccess reads "no
 * subscription" as "no ceiling".
 *
 * Idempotent: matched on `code`, so re-running updates rather than
 * duplicates, and a price somebody has edited by hand is overwritten only
 * because that is what re-seeding a catalogue means.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'blurb' => 'One counter, billing and a QR menu. For a takeaway or a single small outlet.',
                'monthly_price' => 799,
                'yearly_price' => 7990,
                'trial_days' => 14,
                'modules' => ['retail', 'pos', 'customers', 'reports'],
                'max_shops' => 1,
                'max_users' => 5,
                'max_orders_per_month' => 3000,
                'sort_order' => 10,
            ],
            [
                'code' => 'restaurant',
                'name' => 'Restaurant',
                'blurb' => 'The full floor: tables, kitchen display, stock and purchasing. The usual choice.',
                'monthly_price' => 1999,
                'yearly_price' => 19990,
                'trial_days' => 14,
                // Everything except barcode, which is a retail habit rather
                // than a restaurant one and can be added on the plan above.
                'modules' => ['retail', 'pos', 'dining', 'kitchen', 'inventory', 'purchase', 'customers', 'reports'],
                'max_shops' => 3,
                'max_users' => 25,
                'max_orders_per_month' => null,
                'sort_order' => 20,
            ],
            [
                'code' => 'chain',
                'name' => 'Chain',
                'blurb' => 'Every module, every branch, no ceilings. For a group running more than a handful of outlets.',
                'monthly_price' => 4999,
                'yearly_price' => 49990,
                'trial_days' => 0,
                // Null rather than the full list: a module added next year
                // belongs to this plan without anybody remembering to edit
                // a seeder.
                'modules' => null,
                'max_shops' => null,
                'max_users' => null,
                'max_orders_per_month' => null,
                'sort_order' => 30,
            ],
        ];

        foreach ($plans as $attributes) {
            $plan = Plan::withTrashed()->firstOrNew(['code' => $attributes['code']]);

            $plan->fill($attributes);
            $plan->currency = 'INR';
            $plan->is_active = true;

            if ($plan->exists && $plan->trashed()) {
                $plan->restore();
            }

            if (blank($plan->slug)) {
                $plan->slug = $attributes['code'];
            }

            $plan->save();
        }
    }
}
