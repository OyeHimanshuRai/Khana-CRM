<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\BelongsToTenant;
use App\Models\CouponRedemption;
use App\Models\NightlyCheck;
use App\Models\Shop;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * The isolation wall, checked from the schema rather than from memory.
 *
 * Every table that records whose data a row is - a shop_id or a tenant_id -
 * must be read through the scope that enforces it. A model that carries the
 * column and forgets the trait is exactly the shape of a data leak: every
 * list built on it shows every business's rows. This test finds one the day
 * it is written, not the day a customer does.
 *
 * A model may be left unscoped only by being named below with the reason.
 */
class TenantScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each one checked by hand on 26 September 2026: every read of it is
     * narrowed explicitly, in the one place named.
     *
     * @var array<class-string, string>
     */
    private const DELIBERATELY_UNSCOPED = [
        Shop::class => 'Is the boundary itself; reachability is CurrentShop, and ShopController::readable()/authorise() narrow every screen.',
        User::class => 'Staff, mirrored from Accounts; no screen edits other people - ProfileController reads only the signed-in user, ShopController::assignableUsers()/syncMembers() narrow to the shop\'s own business.',
        ActivityLog::class => 'ActivityLogController::readable(): own business only, platform history to the Super Admin alone.',
        SupportTicket::class => 'Belongs to the business, not a branch; SupportTicket::scopeVisibleTo() narrows every read.',
        Address::class => 'A customer\'s own address book; always read by customer and shop (OrderService, Shop\\AddressController).',
        Cart::class => 'A customer\'s own cart; read only through CartService by customer and shop.',
        WishlistItem::class => 'A customer\'s own wishlist; Shop\\WishlistController filters by customer and shop.',
        CouponRedemption::class => 'Written under the customer guard; ReportService::couponsRedeemed() names the branches explicitly.',
        NightlyCheck::class => 'Cross-service bookkeeping keyed by Accounts\' ULIDs (tenant_id/outlet_id are Accounts ids, not CRM\'s tenant FK); written and read only by the crm:nightly command, narrowed by outlet_id there, with no per-user web read.',
    ];

    public function test_every_model_with_an_owner_column_applies_its_scope(): void
    {
        $unscoped = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $owner = array_values(array_intersect(['shop_id', 'tenant_id'], $columns));

            if ($owner === []) {
                continue;
            }

            $traits = class_uses_recursive($class);
            $scoped = in_array(BelongsToShop::class, $traits, true) || in_array(BelongsToTenant::class, $traits, true);

            if (! $scoped && ! array_key_exists($class, self::DELIBERATELY_UNSCOPED)) {
                $unscoped[] = sprintf('%s (%s.%s)', $class, $table, implode(', ', $owner));
            }
        }

        $this->assertSame([], $unscoped, "These models carry an owner column but apply no scope:\n  ".implode("\n  ", $unscoped));
    }
}
