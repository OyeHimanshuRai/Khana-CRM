<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give every existing branch the `dining` module, and drop the role the
     * removed manufacturing module left behind.
     *
     * ----------------------------------------------------------------------
     * Why dining has to be switched on rather than left to the operator
     * ----------------------------------------------------------------------
     *
     * `shops.modules` distinguishes three states, and the distinction is the
     * whole point of the column:
     *
     *   null  nobody has ever been asked — everything is on
     *   []    somebody was asked and unticked every box
     *   [..]  somebody was asked and chose this list
     *
     * A branch configured before `dining` existed is in the third state, but
     * it was asked about *the modules that existed then*. It cannot have said
     * no to one that did not. Leaving those shops alone would take the floor
     * plan, the tables and the QR screen dark for every existing branch on
     * upgrade, which reads as the feature being broken rather than off.
     *
     * Shops on `null` are untouched: null already means everything, and
     * writing a list into them would turn "never asked" into "chose this",
     * which is a worse lie than the one it fixes.
     *
     * This runs once. A shop that genuinely does no dine-in unticks the box
     * afterwards and that choice sticks, because this migration has already
     * run and will not run again.
     */
    public function up(): void
    {
        $this->addDiningModule();
        $this->dropKarigarManagerRole();
    }

    private function addDiningModule(): void
    {
        $shops = DB::table('shops')
            ->whereNotNull('modules')
            ->get(['id', 'modules']);

        foreach ($shops as $shop) {
            $modules = json_decode((string) $shop->modules, true);

            // Not an array means the column holds something this migration
            // does not understand. Leaving it exactly as it is beats guessing.
            if (! is_array($modules) || in_array('dining', $modules, true)) {
                continue;
            }

            $modules[] = 'dining';

            DB::table('shops')
                ->where('id', $shop->id)
                ->update(['modules' => json_encode(array_values($modules))]);
        }
    }

    /**
     * The Karigar Manager role went with the manufacturing module.
     *
     * `permissions:sync --prune` took its permissions but not the role
     * itself, which is correct - pruning permissions has no business
     * deleting roles - so it is left holding five unrelated rights and
     * appearing in every role picker. Removed by name rather than by id, and
     * only when nobody still holds it: a role with users attached is a
     * decision for a person, not for a migration.
     */
    private function dropKarigarManagerRole(): void
    {
        $role = DB::table('roles')->where('name', 'Karigar Manager')->first(['id']);

        if ($role === null) {
            return;
        }

        $held = DB::table('model_has_roles')->where('role_id', $role->id)->exists();

        if ($held) {
            return;
        }

        DB::table('role_has_permissions')->where('role_id', $role->id)->delete();
        DB::table('roles')->where('id', $role->id)->delete();
    }

    /**
     * Not reversed.
     *
     * Taking `dining` back off would switch the floor plan dark for branches
     * that have been using it, and there is no record here of which shops
     * this migration added it to as against which ones ticked it themselves.
     */
    public function down(): void
    {
        // Deliberately empty. See the note on up().
    }
};
