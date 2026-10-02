<?php

namespace Tests\Concerns;

use App\Models\Floor;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Khana\Kit\Events\Signature;
use Khana\Kit\Support\Ulid;

/**
 * One business with more than one outlet, and what reaches each of them.
 *
 * The things a test about "each outlet's own truth" keeps needing: a second
 * outlet of the same business, a table a guest can scan at either, somebody
 * working at one of them, and ERP saying a dish ran out at one of them - a
 * signed batch posted to /internal/events, the way it really arrives.
 *
 * Used beside TalksToErp, whose linkToAccounts() gives an outlet its Accounts
 * ids and what it bought.
 */
trait RunsTwoOutlets
{
    private int $erpSeq = 0;

    /** Another outlet of the same business as `$first`. */
    protected function anotherOutlet(Shop $first, string $name = 'Riverside', string $code = 'RIVER'): Shop
    {
        return Shop::query()->create([
            'tenant_id' => $first->tenant_id,
            'name' => $name,
            'code' => $code,
            'slug' => Shop::uniqueSlug($name),
            'currency' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'is_active' => true,
        ])->fresh();
    }

    protected function dishNamed(string $name, float $price = 200, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'slug' => Product::uniqueSlug($name),
            'sku' => Product::generateSku($name),
            'selling_price' => $price,
            'tax_inclusive' => true,
            'is_active' => true,
        ], $overrides))->fresh();
    }

    protected function sizeOf(Product $dish, string $name, float $price, bool $default = false): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $dish->id,
            'name' => $name,
            'price' => $price,
            'is_default' => $default,
            'is_available' => true,
        ])->fresh();
    }

    /** A table with a sticker on it, at the given outlet. */
    protected function tableAt(Shop $shop, string $code = 'GF-01'): RestaurantTable
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $shop->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $shop->id,
            'floor_id' => $floor->id,
            'name' => $code,
            'code' => $code,
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    /** Scan the sticker, the way a phone does. The sitting moves to this table. */
    protected function seatAt(RestaurantTable $table): TableSession
    {
        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        return TableSession::allShops()
            ->where('restaurant_table_id', $table->id)
            ->live()
            ->firstOrFail();
    }

    /**
     * Somebody who works at one outlet, with the given rights.
     *
     * @param  list<string>  $permissions
     */
    protected function staffAt(Shop $shop, array $permissions, ?string $email = null): User
    {
        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Staff at '.$shop->name,
            'email' => $email ?? 'staff'.$shop->id.'.'.strtolower(substr(Ulid::generate(), -6)).'@outlets.example.test',
            'password' => 'outlet-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(['dashboard.overview.view', ...$permissions]);
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();

        return $user->fresh();
    }

    /**
     * Forget who the last request was signed in as.
     *
     * A test's requests share one application, so a cashier acted as in one
     * line is still signed in on the next - and a guest's page that only
     * worked because somebody happened to be signed in would pass.
     */
    protected function asNobody(): void
    {
        $this->app['auth']->forgetGuards();

        CurrentTenant::forget();
        CurrentShop::forget();
    }

    /* ------------------------------------------------------- ERP's word */

    /**
     * erp.availability.changed for one outlet, as it travels - checked
     * against its contract first, so no test passes on a shape ERP would
     * not send.
     *
     * @return array<string, mixed>
     */
    protected function stockEvent(Shop $shop, Product $dish, bool $available, ?ProductVariant $size = null): array
    {
        $shop = $shop->fresh();

        $tenant = DB::table('tenants')->where('id', $shop->tenant_id)->value('ulid');

        $data = [
            'item_id' => $dish->fresh()->ulid,
            'variant_id' => $size?->fresh()->ulid,
            'available' => $available,
            'reason' => $available ? 'restocked' : 'stock_out',
        ];

        $this->assertDataHonours('erp.availability.changed', $data, $tenant, $shop->ulid, 'erp');

        return [
            'id' => Ulid::generate(),
            'type' => 'erp.availability.changed',
            'version' => 1,
            'source' => 'erp',
            'seq' => ++$this->erpSeq,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'tenant_id' => $tenant,
            'outlet_id' => $shop->ulid,
            'data' => $data,
        ];
    }

    /** Post a signed batch from ERP to the inbox. */
    protected function deliverFromErp(array ...$events): TestResponse
    {
        config(['khana.events.senders.erp' => 'erp-to-crm-test-secret']);

        $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/internal/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_KHANA_SOURCE' => 'erp',
            'HTTP_X_KHANA_SIGNATURE' => Signature::sign($body, 'erp-to-crm-test-secret'),
        ], $body);
    }

    /** ERP says the dish (or one size) ran out at this outlet. */
    protected function runsOutAt(Shop $shop, Product $dish, ?ProductVariant $size = null): void
    {
        $this->deliverFromErp($this->stockEvent($shop, $dish, false, $size))->assertOk();
    }

    /** ERP says it is back. */
    protected function comesBackAt(Shop $shop, Product $dish, ?ProductVariant $size = null): void
    {
        $this->deliverFromErp($this->stockEvent($shop, $dish, true, $size))->assertOk();
    }
}
