<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * The spending categories nearly every shop has.
     *
     * Seeded with a null shop_id, which means "available everywhere" - a
     * new branch inherits them without anybody re-typing the list. A shop
     * that wants its own can add them alongside.
     */
    public function run(): void
    {
        $categories = [
            ['Rent', 'Shop, godown or land rent'],
            ['Electricity', 'Power bills'],
            ['Salaries & Wages', 'Staff pay, including casual labour'],
            ['Transport & Freight', 'Delivery, loading, van running costs'],
            ['Fuel', 'Diesel and petrol'],
            ['Telephone & Internet', 'Connections and recharges'],
            ['Repairs & Maintenance', 'Building, equipment, vehicles'],
            ['Printing & Stationery', 'Bill books, labels, office supplies'],
            ['Licences & Fees', 'Dealer licences, renewals, professional fees'],
            ['Marketing', 'Boards, pamphlets, farmer meetings'],
            ['Bank Charges', 'Transaction and account charges'],
            ['Miscellaneous', 'Anything that fits nowhere else'],
        ];

        foreach ($categories as $index => [$name, $description]) {
            ExpenseCategory::firstOrCreate(
                ['shop_id' => null, 'name' => $name],
                [
                    'description' => $description,
                    'is_active' => true,
                    'sort_order' => $index,
                ]
            );
        }

        $this->command?->info(sprintf('  %d expense categories.', count($categories)));
    }
}
