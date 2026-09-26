<?php

namespace Khana\Kit\Events;

use Illuminate\Support\Facades\DB;

/**
 * Numbers committed events in the order they became visible.
 *
 * `seq` is what receivers order by and what a cursor counts along, so it has
 * to be handed out after commit, never at insert: an uncommitted row is
 * invisible, and a number given to it early would sit behind numbers that
 * had already been sent. The row lock on the sequence table makes the
 * numbering single-file even when two workers run it at once.
 */
final class Sequencer
{
    public function run(int $limit = 1000): int
    {
        return DB::transaction(function () use ($limit) {
            $last = (int) DB::table('khana_outbox_sequence')->where('id', 1)->lockForUpdate()->value('last_seq');

            $ids = OutboxEvent::query()
                ->whereNull('seq')
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            foreach ($ids as $id) {
                OutboxEvent::query()->whereKey($id)->update(['seq' => ++$last]);
            }

            if ($ids->isNotEmpty()) {
                DB::table('khana_outbox_sequence')->where('id', 1)->update(['last_seq' => $last]);
            }

            return $ids->count();
        });
    }
}
