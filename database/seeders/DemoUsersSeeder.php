<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Optional demo data for exercising the user listing.
 *
 * Deliberately NOT called from DatabaseSeeder - run it on purpose:
 *
 *   php artisan db:seed --class=DemoUsersSeeder
 *
 * Every account it creates uses the address prefix "demo." so they can be
 * removed again in one query:
 *
 *   User::where('email', 'like', 'demo.%@erp.test')->delete();
 */
class DemoUsersSeeder extends Seeder
{
    /** @var array<int, array{0: string, 1: string, 2: bool}> */
    private const PEOPLE = [
        ['Aarav Sharma', 'Admin', true],
        ['Diya Kapoor', 'Manager', true],
        ['Rohan Gupta', 'Staff', true],
        ['Ishita Rao', 'Auditor', true],
        ['Vikram Desai', 'Manager', false],
        ['Nisha Patel', 'Staff', true],
        ['Arjun Mehta', 'Admin', true],
        ['Kavya Reddy', 'Auditor', false],
        ['Sameer Khan', 'Staff', true],
        ['Tanvi Joshi', 'Manager', true],
        ['Rahul Bose', 'Staff', false],
        ['Ananya Nair', 'Auditor', true],
        ['Karan Bhatia', 'Staff', true],
        ['Meera Pillai', 'Manager', true],
        ['Dev Saxena', 'Staff', true],
        ['Pooja Iyer', 'Admin', true],
    ];

    public function run(): void
    {
        foreach (self::PEOPLE as $index => [$name, $role, $access]) {
            $slug = str_replace(' ', '.', strtolower($name));

            $user = User::firstOrCreate(
                ['email' => "demo.{$slug}@erp.test"],
                [
                    'name' => $name,
                    'password' => 'password',
                    'is_admin' => $access,
                    'email_verified_at' => now(),
                ]
            );

            $user->syncRoles([$role]);

            // Spread the timestamps so the sort options have something to order by.
            $user->forceFill(['created_at' => now()->subDays(($index + 1) * 3)])->save();
        }

        $this->command?->info('  '.count(self::PEOPLE).' demo users created (password: "password").');
    }
}
