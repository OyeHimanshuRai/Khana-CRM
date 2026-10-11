<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A CSV of sales from other billing software, and what became of it. */
class SalesImport extends Model
{
    use InOutlet;

    protected $fillable = ['tenant_id', 'outlet_id', 'file_name', 'rows', 'sales', 'skipped', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
