<?php

/*
 | Every model, against its own table.
 |
 | Asks one question per model and answers it from the schema rather than from
 | anybody's memory: this row belongs to a branch, or to a company, or to
 | nobody - and does the model actually say so?
 |
 | A table carrying shop_id or tenant_id whose model applies no scope is the
 | exact shape of the leak found in the catalogue, so those are printed first.
 */

use Illuminate\Support\Str;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = [];

foreach (glob(base_path('app/Models/*.php')) as $file) {
    $class = 'App\\Models\\'.basename($file, '.php');

    if (! class_exists($class)) {
        continue;
    }

    $ref = new ReflectionClass($class);

    if ($ref->isAbstract() || ! $ref->isSubclassOf(Illuminate\Database\Eloquent\Model::class)) {
        continue;
    }

    /** @var Illuminate\Database\Eloquent\Model $model */
    $model = new $class();
    $table = $model->getTable();

    if (! Schema::hasTable($table)) {
        $rows[] = [basename($file, '.php'), $table, 'NO TABLE', '-', '-', 0];
        continue;
    }

    $columns = Schema::getColumnListing($table);
    $traits = class_uses_recursive($class);

    $hasShop = in_array('shop_id', $columns, true);
    $hasTenant = in_array('tenant_id', $columns, true);

    $scoped = match (true) {
        in_array(App\Models\Concerns\BelongsToShop::class, $traits, true) => 'shop scope',
        in_array(App\Models\Concerns\BelongsToTenant::class, $traits, true) => 'tenant scope',
        default => 'none',
    };

    $column = match (true) {
        $hasShop => 'shop_id',
        $hasTenant => 'tenant_id',
        default => '-',
    };

    $verdict = match (true) {
        $column !== '-' && $scoped === 'none' => 'LEAK?',
        $column === '-' && $scoped === 'none' => 'global',
        default => 'ok',
    };

    $rows[] = [
        basename($file, '.php'),
        $table,
        $column,
        $scoped,
        $verdict,
        (int) DB::table($table)->count(),
    ];
}

usort($rows, fn ($a, $b) => [$a[4] === 'LEAK?' ? 0 : ($a[4] === 'global' ? 1 : 2), $a[0]]
    <=> [$b[4] === 'LEAK?' ? 0 : ($b[4] === 'global' ? 1 : 2), $b[0]]);

printf("%-24s %-26s %-10s %-13s %-7s %s\n", 'MODEL', 'TABLE', 'COLUMN', 'SCOPE', 'VERDICT', 'ROWS');
echo str_repeat('-', 96), PHP_EOL;

foreach ($rows as $r) {
    printf("%-24s %-26s %-10s %-13s %-7s %d\n", ...$r);
}

$leaks = array_filter($rows, fn ($r) => $r[4] === 'LEAK?');
echo PHP_EOL, count($leaks), ' model(s) carry an owner column and apply no scope.', PHP_EOL;
