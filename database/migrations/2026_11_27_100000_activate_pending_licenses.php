<?php

use App\Models\License;
use App\Services\LicenseService;
use Illuminate\Database\Migrations\Migration;

/**
 * A license no longer waits for a second approval: issuing the key is the
 * platform's decision, and entering it activates the license and starts the
 * plan. Customers who entered their key before that change are still sitting
 * on "Waiting for approval"; this activates them the same way.
 *
 * One at a time and never fatal: a license that cannot be activated (no plan, a
 * date already past) stays pending for the Super Admin to look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        License::query()->where('status', License::PENDING)->with(['tenant', 'plan'])->each(function (License $license) {
            try {
                app(LicenseService::class)->approve($license, null);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /** Not reversed: an activated license is a fact, not a switch. */
    public function down(): void
    {
        // Deliberately empty.
    }
};
