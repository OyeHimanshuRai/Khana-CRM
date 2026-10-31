<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| The online storefront becomes a module of its own (config/modules.php).
|
| Until now every active outlet had one, whatever its plan or its own module
| list said. A plan or a shop that names its modules explicitly would lose the
| storefront the moment the module exists, so this adds it to every explicit
| list: on upgrade nobody's storefront closes, and from here on a plan can
| leave it out and an outlet can switch it off. A null list already means
| "every module" and is left alone.
*/
return new class extends Migration
{
    public function up(): void
    {
        foreach (['plans', 'shops'] as $table) {
            $this->rewrite($table, function (array $modules): array {
                return in_array('storefront', $modules, true) ? $modules : [...$modules, 'storefront'];
            });
        }
    }

    public function down(): void
    {
        foreach (['plans', 'shops'] as $table) {
            $this->rewrite($table, fn (array $modules): array => array_values(array_diff($modules, ['storefront'])));
        }
    }

    private function rewrite(string $table, Closure $change): void
    {
        DB::table($table)->whereNotNull('modules')->orderBy('id')->each(function (object $row) use ($table, $change) {
            $modules = json_decode((string) $row->modules, true);

            if (! is_array($modules)) {
                return;
            }

            DB::table($table)->where('id', $row->id)->update(['modules' => json_encode(array_values($change($modules)))]);
        });
    }
};
