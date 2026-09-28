<?php

namespace App\Events\Outbound;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Khana\Kit\Support\Ulid;

/**
 * The ulid of a row CRM itself identifies to ERP, minted the first time it
 * leaves if the row has none yet.
 *
 * HasPublicId gives every new row one, and the migrations backfilled what
 * existed - but a row written with model events off has none: everything
 * DatabaseSeeder writes (it runs WithoutModelEvents), and anything saved
 * with saveQuietly(). Sending such a row with a null id would break its
 * contract, and refusing to send it would lose a sale. So the id is made
 * here, written straight to the row (no timestamps touched, no events
 * fired), and is the row's id from then on.
 *
 * Only for what CRM mints: sales and their lines, returns, kitchen orders
 * and lines, menu items and their parts, paid-outs. Never for a business or
 * an outlet - those are Accounts' ids, and a row without one is not linked
 * to Accounts yet (CrmEvents skips it rather than invent one).
 */
final class PublicIds
{
    public static function of(Model $model): string
    {
        $current = $model->getAttribute('ulid');

        if (Ulid::isValid($current)) {
            return $current;
        }

        $ulid = self::mint($model->getTable(), $model->getKey());

        $model->setAttribute('ulid', $ulid);
        $model->syncOriginalAttribute('ulid');

        return $ulid;
    }

    /**
     * id => ulid for these rows of one model, minting any that are missing.
     *
     * @param  class-string<Model>  $model
     * @param  iterable<int|string|null>  $ids
     * @return Collection<int, string>
     */
    public static function map(string $model, iterable $ids): Collection
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $table = (new $model())->getTable();

        return DB::table($table)
            ->whereIn('id', $ids)
            ->pluck('ulid', 'id')
            ->map(fn ($ulid, $id) => Ulid::isValid($ulid) ? $ulid : self::mint($table, $id));
    }

    private static function mint(string $table, int|string $id): string
    {
        $ulid = Ulid::generate();

        DB::table($table)->where('id', $id)->update(['ulid' => $ulid]);

        return $ulid;
    }
}
