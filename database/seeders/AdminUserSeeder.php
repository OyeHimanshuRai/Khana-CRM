<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Create the default administrator account.
     *
     * Idempotent, so re-running `db:seed` will not fail on the unique email
     * or silently reset a password that has since been changed.
     *
     * In production a missing or well-known password is refused rather than
     * used: the fallback exists so a developer can seed a laptop, and a live
     * super-admin account that signs in with "password" is a takeover waiting
     * for someone to try it.
     */
    public function run(): void
    {
        $email = config('admin.default_email');
        $password = (string) config('admin.default_password');

        if (app()->isProduction()
            && ! User::query()->withoutGlobalScopes()->where('email', $email)->exists()
            && (strlen($password) < 12 || in_array(strtolower($password), ['password', 'admin', 'secret', '12345678'], true))) {
            throw new RuntimeException(
                'Set ADMIN_EMAIL and a strong ADMIN_PASSWORD (12+ characters) in .env before seeding in production.'
            );
        }

        User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Administrator',
                'password' => $password,
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );
    }
}
