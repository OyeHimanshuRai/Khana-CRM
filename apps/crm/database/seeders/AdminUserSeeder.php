<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the default administrator account.
     *
     * Idempotent, so re-running `db:seed` will not fail on the unique email
     * or silently reset a password that has since been changed.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => config('admin.default_email')],
            [
                'name' => 'Administrator',
                'password' => config('admin.default_password'),
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
