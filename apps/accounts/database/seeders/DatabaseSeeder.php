<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    // Note: no WithoutModelEvents here - the ulid every row carries is set on
    // the `creating` event (HasPublicId), so events must fire while seeding.
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        // A platform admin to sign in to the back office with. Belongs to no
        // business, so isPlatformAdmin() holds. forceFill because the
        // platform flag and status are guarded, not mass-assignable.
        $admin = User::firstOrNew(['email' => 'admin@khana.test']);

        if (! $admin->exists) {
            $admin->forceFill([
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'status' => User::ACTIVE,
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ])->save();
        }
    }
}
