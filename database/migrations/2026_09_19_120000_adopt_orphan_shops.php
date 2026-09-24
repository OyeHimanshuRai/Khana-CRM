<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Put every branch back under the company it belongs to.
     *
     * ----------------------------------------------------------------------
     * How a branch came to belong to nobody
     * ----------------------------------------------------------------------
     *
     * `shops.tenant_id` was added nullable, and the migration that added it
     * said why: null was meant to last only from that migration to the
     * seeder adopting the rows. It is not a state a shop is ever supposed to
     * rest in - Shop::booted says the same thing in code, and the reason is
     * practical rather than tidy. An orphan cannot be put on a plan, because
     * a subscription is billed to a company. Its own staff cannot reach it,
     * because they are narrowed to their company's branches. And it appears
     * under no company on the screens that list them, so nobody finds it.
     *
     * They got there through an edit. ShopController::attributes mapped an
     * absent `tenant_id` to null, the shop form carries no company field, so
     * every edit any screen could post wrote that null over a correct
     * tenant - and `Shop::creating`, which fills the column in, does not
     * fire on an update to put it back. Changing a branch's phone number
     * orphaned it, silently. Both halves of that are fixed at the source;
     * this migration is for the rows already written.
     *
     * ----------------------------------------------------------------------
     * Which company each one goes back to
     * ----------------------------------------------------------------------
     *
     * Two sources of truth, in this order, and nothing else:
     *
     *   1. Its own subscription. `subscriptions.tenant_id` records who was
     *      billed for this outlet, and money is the most reliable statement
     *      of ownership in the database. The column survived because the
     *      subscription was written before the edit that orphaned the shop.
     *
     *   2. The only company there is. On a single-business install there is
     *      exactly one answer and it cannot be wrong - the same fallback
     *      Shop::creating uses, and it stops being available the moment a
     *      second business exists.
     *
     * A shop that neither source can place is left exactly as it is. Filing
     * somebody's restaurant under another firm is worse than leaving a row
     * for a person to look at, and the note below says which rows those are.
     */
    public function up(): void
    {
        $orphans = DB::table('shops')->whereNull('tenant_id')->pluck('id');

        if ($orphans->isEmpty()) {
            return;
        }

        // Null the moment a second business exists, which is what makes the
        // fallback safe to reach for.
        $soleTenantId = DB::table('tenants')->count() === 1
            ? DB::table('tenants')->value('id')
            : null;

        foreach ($orphans as $shopId) {
            $tenantId = DB::table('subscriptions')
                ->where('shop_id', $shopId)
                ->whereNotNull('tenant_id')
                ->orderByDesc('id')
                ->value('tenant_id') ?? $soleTenantId;

            if ($tenantId === null) {
                continue;
            }

            DB::table('shops')
                ->where('id', $shopId)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }
    }

    /**
     * Not reversed.
     *
     * Down would have to blank the column again, which is the bug this
     * exists to undo. There is no record here of which shops were orphaned
     * as against which were always filed correctly, and recreating the
     * damage from a guess is not a rollback.
     */
    public function down(): void
    {
        // Deliberately empty. See the note on up().
    }
};
