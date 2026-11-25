<?php

/*
| One-off: permanently erase the soft-removed test companies and everything that belongs to them.
| Run through `php artisan tinker`. PURGE_COMMIT=1 commits (after a database backup); anything else
| is a dry run that is rolled back and only prints what it would delete.
*/

use Illuminate\Support\Facades\DB;

$commit = getenv('PURGE_COMMIT') === '1';
$expect = [5 => 'bikasweets', 17 => 'Deepak', 16 => 'Side Restra', 7 => 'test', 13 => 'test', 14 => 'Test', 15 => 'test'];
$tenantIds = array_keys($expect);

// Only companies already soft-removed, matching name, and never the in-house one.
$rows = DB::table('tenants')->whereIn('id', $tenantIds)->get(['id', 'name', 'code', 'deleted_at']);
if ($rows->count() !== count($expect)) { echo 'ABORT: expected '.count($expect).' companies, found '.$rows->count().PHP_EOL; return; }
foreach ($rows as $r) {
    if ($r->deleted_at === null || $r->name !== $expect[$r->id] || $r->code === 'MAIN') { echo 'ABORT: tenant '.$r->id.' is not a soft-removed '.$expect[$r->id].PHP_EOL; return; }
}

$db = DB::getDatabaseName();
$shopIds = DB::table('shops')->whereIn('tenant_id', $tenantIds)->pluck('id')->map(fn ($v) => (int) $v)->all();
echo 'mode: '.($commit ? 'COMMIT' : 'DRY RUN (rolled back)').'; tenants '.count($tenantIds).', shops '.count($shopIds).PHP_EOL;

$cols = collect(DB::select('select table_name t, column_name c, is_nullable n from information_schema.columns where table_schema = ?', [$db]));
$hasCol = fn ($t, $c) => $cols->contains(fn ($x) => $x->t === $t && $x->c === $c);
$nullable = fn ($t, $c) => optional($cols->first(fn ($x) => $x->t === $t && $x->c === $c))->n === 'YES';
$fks = collect(DB::select('select table_name t, column_name c, referenced_table_name rt, referenced_column_name rc from information_schema.key_column_usage where table_schema = ? and referenced_table_name is not null', [$db]));
$byParent = $fks->groupBy('rt');

$tList = implode(',', array_map('intval', $tenantIds));
$sList = $shopIds ? implode(',', $shopIds) : '0';

// SQL that is true for rows that belong to the removed companies (NULL-safe).
$ownSql = function (string $t) use ($hasCol, $tList, $sList) {
    $parts = [];
    if ($hasCol($t, 'tenant_id')) { $parts[] = 'COALESCE(`tenant_id` IN ('.$tList.'), 0)'; }
    if ($hasCol($t, 'shop_id')) { $parts[] = 'COALESCE(`shop_id` IN ('.$sList.'), 0)'; }
    return $parts ? '('.implode(' OR ', $parts).')' : '0';
};

$deleted = [];
$nulled = [];
$visited = [];

$purge = null;
$purge = function (string $table, string $col, array $values) use (&$purge, &$deleted, &$nulled, &$visited, $byParent, $nullable, $ownSql) {
    if (! $values) { return; }
    $key = $table.'|'.$col.'|'.md5(json_encode($values));
    if (isset($visited[$key])) { return; }
    $visited[$key] = true;

    foreach ($byParent[$table] ?? [] as $fk) {
        $parentVals = DB::table($table)->whereIn($col, $values)->pluck($fk->rc)->unique()->filter(fn ($v) => $v !== null)->values()->all();
        foreach (array_chunk($parentVals, 500) as $chunk) {
            if ($nullable($fk->t, $fk->c)) {
                // A soft link from somebody else's row (a Super Admin parked in the company): detach it, never delete it.
                $n = DB::table($fk->t)->whereIn($fk->c, $chunk)->whereRaw('NOT '.$ownSql($fk->t))->update([$fk->c => null]);
                if ($n) { $nulled[$fk->t.'.'.$fk->c] = ($nulled[$fk->t.'.'.$fk->c] ?? 0) + $n; }
            }
            $purge($fk->t, $fk->c, $chunk);
        }
    }

    $n = DB::table($table)->whereIn($col, $values)->delete();
    if ($n) { $deleted[$table] = ($deleted[$table] ?? 0) + $n; }
};

if ($commit) {
    $cfg = config('database.connections.'.config('database.default'));
    $dir = dirname(base_path()).'/db-backups';
    @mkdir($dir, 0700, true);
    $file = $dir.'/before-purge-'.date('Ymd-His').'.sql.gz';
    putenv('MYSQL_PWD='.($cfg['password'] ?? ''));
    exec('mysqldump -h '.escapeshellarg($cfg['host']).' -P '.escapeshellarg((string) ($cfg['port'] ?? 3306)).' -u '.escapeshellarg($cfg['username']).' --single-transaction --no-tablespaces '.escapeshellarg($cfg['database']).' 2>/dev/null | gzip > '.escapeshellarg($file), $o, $code);
    putenv('MYSQL_PWD');
    if (! is_file($file) || filesize($file) < 2000) { echo 'ABORT: the backup failed, nothing was deleted'.PHP_EOL; return; }
    echo 'backup written: '.$file.' ('.filesize($file).' bytes)'.PHP_EOL;
}

DB::beginTransaction();

try {
    // 1. Everything that hangs off the companies through a declared foreign key.
    $purge('tenants', 'id', $tenantIds);

    // 2. Rows that carry a tenant_id / shop_id with no foreign key.
    foreach ($cols->pluck('t')->unique() as $t) {
        if (in_array($t, ['tenants', 'migrations'], true)) { continue; }
        $own = $ownSql($t);
        if ($own === '0') { continue; }
        try {
            $n = DB::table($t)->whereRaw($own)->delete();
            if ($n) { $deleted[$t] = ($deleted[$t] ?? 0) + $n; }
        } catch (\Throwable $e) {
            echo 'WARN '.$t.': '.substr($e->getMessage(), 0, 160).PHP_EOL;
        }
    }

    // 3. Polymorphic rows (roles, permissions, API tokens) point at users with no foreign key: tidy the ones left behind.
    foreach ([['model_has_roles', 'model_type', 'model_id'], ['model_has_permissions', 'model_type', 'model_id'], ['personal_access_tokens', 'tokenable_type', 'tokenable_id']] as [$t, $typeCol, $idCol]) {
        if (! $cols->contains(fn ($x) => $x->t === $t)) { continue; }
        $n = DB::table($t)->where($typeCol, 'like', '%User')->whereNotIn($idCol, DB::table('users')->select('id'))->delete();
        if ($n) { $deleted[$t] = ($deleted[$t] ?? 0) + $n; }
    }

    ksort($deleted);
    foreach ($deleted as $t => $n) { echo ($commit ? 'deleted ' : 'would delete ').$n.' from '.$t.PHP_EOL; }
    foreach ($nulled as $k => $n) { echo 'detached '.$n.' link(s) in '.$k.PHP_EOL; }
    echo 'tenants left: '.DB::table('tenants')->count().', in-house exists: '.(int) DB::table('tenants')->where('code', 'MAIN')->exists().PHP_EOL;
    $supers = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->where('roles.name', 'Super Admin')->count();
    echo 'users left: '.DB::table('users')->count().', Super Admin role holders: '.$supers.PHP_EOL;
    echo 'other companies untouched: '.DB::table('tenants')->whereNull('deleted_at')->pluck('name')->implode(', ').PHP_EOL;

    if ($commit) { DB::commit(); echo 'COMMITTED'.PHP_EOL; } else { DB::rollBack(); echo 'ROLLED BACK (dry run)'.PHP_EOL; }
} catch (\Throwable $e) {
    DB::rollBack();
    echo 'FAILED, rolled back: '.substr($e->getMessage(), 0, 300).PHP_EOL;
}
