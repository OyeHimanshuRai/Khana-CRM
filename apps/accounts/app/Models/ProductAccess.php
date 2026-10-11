<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A person's role in one product, and at which outlets.
 *
 * @property int $id
 * @property int $user_id
 * @property string $product
 * @property string $role_key
 * @property bool $all_outlets
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Outlet> $outlets
 */
class ProductAccess extends Model
{
    protected $table = 'product_access';

    protected $fillable = ['product', 'role_key', 'all_outlets'];

    protected function casts(): array
    {
        return ['all_outlets' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Outlet, $this> */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'product_access_outlets');
    }

    /**
     * The outlets this access reaches right now. "All outlets" includes any
     * opened after it was given, which is what an owner expects.
     *
     * @return \Illuminate\Support\Collection<int, Outlet>
     */
    public function reachableOutlets(): \Illuminate\Support\Collection
    {
        if ($this->all_outlets) {
            return Outlet::query()->where('tenant_id', $this->user->tenant_id)->orderBy('name')->get();
        }

        return $this->outlets->where('tenant_id', $this->user->tenant_id)->sortBy('name')->values();
    }
}
