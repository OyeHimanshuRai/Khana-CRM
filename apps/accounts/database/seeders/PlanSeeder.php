<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The three things the platform sells: CRM on its own, ERP on its own, and
 * both as the Suite - the one that turns the link between the products on.
 * Prices are in paise; a plan may be re-priced without moving anyone on it.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'crm', 'name' => 'CRM', 'blurb' => 'Front of house: POS, tables, KOT, customers',
                'products' => ['crm'],
                'monthly_price_paise' => 99900, 'yearly_price_paise' => 999000,
                'trial_days' => 14, 'sort_order' => 1,
            ],
            [
                'code' => 'erp', 'name' => 'ERP', 'blurb' => 'Back of house: stock, recipes, purchasing',
                'products' => ['erp'],
                'monthly_price_paise' => 99900, 'yearly_price_paise' => 999000,
                'trial_days' => 14, 'sort_order' => 2,
            ],
            [
                'code' => 'suite', 'name' => 'Suite', 'blurb' => 'CRM and ERP together - sold-out from stock, cost on the menu',
                'products' => ['crm', 'erp'],
                'monthly_price_paise' => 149900, 'yearly_price_paise' => 1499000,
                'trial_days' => 14, 'sort_order' => 0,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan + ['is_active' => true]);
        }
    }
}
