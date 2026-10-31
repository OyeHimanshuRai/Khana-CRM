<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| Expense bills move from the public disk to the private one (see
| Expense::ATTACHMENT_DISK). The path stays the same, so the row needs no
| change - only the file moves. A file that is already private, or missing
| from both, is left as it is; one that cannot be copied stays public rather
| than being lost.
*/
return new class extends Migration
{
    public function up(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');

        DB::table('expenses')->whereNotNull('attachment_path')->orderBy('id')->each(function (object $row) use ($public, $private) {
            $path = (string) $row->attachment_path;

            if ($path === '' || $private->exists($path) || ! $public->exists($path)) {
                return;
            }

            if ($private->put($path, $public->get($path))) {
                $public->delete($path);
            }
        });
    }

    public function down(): void
    {
        // Not moved back: making private paperwork public again is not an undo anyone wants.
    }
};
