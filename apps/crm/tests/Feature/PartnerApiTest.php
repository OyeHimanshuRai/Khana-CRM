<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Floor;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableCartService;
use App\Services\TableOrderService;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

// The Partner app's own endpoints: a table's bill, the owner's dashboard, outlets and push devices.
class PartnerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function staff(array $permissions, ?Shop $shop = null): User
    {
        $shop ??= $this->shop();

        $user = User::factory()->create(['tenant_id' => $shop->tenant_id, 'is_admin' => true]);
        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    private function headers(User $user, array $abilities): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('App: test', $abilities)->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function seatedTable(): RestaurantTable
    {
        $floor = Floor::query()->firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        $table = new RestaurantTable([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '04',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
        $table->save();
        app(TableQrService::class)->issue($table);

        $this->get('/t/'.$table->fresh(['activeQr'])->activeQr->token)->assertRedirect(route('table.show'));
        $session = TableSession::allShops()->live()->where('restaurant_table_id', $table->id)->firstOrFail();

        $dish = new Product(['name' => 'Butter Naan', 'slug' => Product::uniqueSlug('Butter Naan'), 'sku' => Product::generateSku('Butter Naan'), 'selling_price' => 60, 'is_active' => true]);
        $dish->save();

        app(TableCartService::class)->add($session, $dish, null, 3);
        app(TableOrderService::class)->place($session);
        CurrentShop::forget();

        return $table;
    }

    public function test_a_captain_sees_what_a_table_has_ordered_and_owes(): void
    {
        $table = $this->seatedTable();
        $captain = $this->staff(['pos.tables.view']);

        $response = $this->getJson("/api/v1/tables/{$table->id}", $this->headers($captain, ['tables:read']))
            ->assertOk()
            ->assertJsonPath('name', '04')
            ->assertJsonPath('session.lines.0.name', 'Butter Naan')
            ->assertJsonPath('session.lines.0.quantity', 3);

        $this->assertEqualsWithDelta(180, $response->json('session.unbilled'), 0.01);
    }

    public function test_a_free_table_has_no_session(): void
    {
        $floor = Floor::query()->create(['shop_id' => $this->shop()->id, 'code' => 'RT', 'name' => 'Roof', 'is_active' => true]);
        $table = RestaurantTable::query()->create([
            'shop_id' => $floor->shop_id, 'floor_id' => $floor->id, 'name' => '09', 'code' => 'RT-09',
            'capacity' => 2, 'status' => RestaurantTable::AVAILABLE, 'is_active' => true,
        ]);

        $this->getJson("/api/v1/tables/{$table->id}", $this->headers($this->staff([]), ['tables:read']))
            ->assertOk()
            ->assertJsonPath('session', null);
    }

    public function test_the_table_needs_its_ability(): void
    {
        $table = $this->seatedTable();

        $this->getJson("/api/v1/tables/{$table->id}", $this->headers($this->staff([]), ['menu:read']))
            ->assertForbidden();
    }

    public function test_the_dashboard_shows_only_the_tiles_the_person_may_see(): void
    {
        $owner = $this->staff(['dashboard.overview.view', 'reports.sales_report.view']);

        $this->getJson('/api/v1/dashboard', $this->headers($owner, ['dashboard:read']))
            ->assertOk()
            ->assertJsonPath('shop.id', $this->shop()->id)
            ->assertJsonStructure(['sales' => ['today', 'today_count', 'month'], 'alerts'])
            ->assertJsonPath('profit', null);
    }

    public function test_the_dashboard_needs_its_ability(): void
    {
        $this->getJson('/api/v1/dashboard', $this->headers($this->staff(['dashboard.overview.view']), ['tables:read']))
            ->assertForbidden();
    }

    public function test_outlets_list_and_switch_only_to_ones_the_person_works_at(): void
    {
        $first = $this->shop();
        $branch = function (string $name, string $code) use ($first): Shop {
            $shop = new Shop(['tenant_id' => $first->tenant_id, 'name' => $name, 'code' => $code, 'is_active' => true]);
            $shop->slug = Shop::uniqueSlug($name);
            $shop->save();

            return $shop;
        };
        $second = $branch('Second Branch', 'SEC2');
        $elsewhere = $branch('Not Mine', 'NOTM');

        $user = $this->staff([]);
        $user->shops()->syncWithoutDetaching([$second->id => ['is_default' => false]]);
        $headers = $this->headers($user, ['tables:read']);

        $ids = collect($this->getJson('/api/v1/outlets', $headers)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);

        $this->putJson('/api/v1/outlet', ['shop_id' => $second->id], $headers)
            ->assertOk()
            ->assertJsonPath('shop.id', $second->id);
        $this->assertSame($second->id, (int) $user->fresh()->current_shop_id);

        CurrentShop::forget();
        $this->putJson('/api/v1/outlet', ['shop_id' => $elsewhere->id], $headers)->assertForbidden();
    }

    public function test_a_phone_registers_once_and_can_stop_its_notifications(): void
    {
        $user = $this->staff([]);
        $headers = $this->headers($user, ['tables:read']);

        $this->postJson('/api/v1/devices', ['token' => 'fcm-token-abc', 'label' => 'Ravi phone'], $headers)->assertCreated();
        $this->postJson('/api/v1/devices', ['token' => 'fcm-token-abc'], $headers)->assertCreated();

        $rows = DeviceToken::query()->withoutGlobalScopes()->where('endpoint_hash', DeviceToken::hashFor('fcm-token-abc'))->get();
        $this->assertCount(1, $rows);
        $this->assertSame(DeviceToken::FCM, $rows->first()->kind);
        $this->assertSame($user->id, (int) $rows->first()->user_id);

        $this->deleteJson('/api/v1/devices', ['token' => 'fcm-token-abc'], $headers)->assertOk();
        $this->assertSame(0, DeviceToken::query()->withoutGlobalScopes()->where('endpoint_hash', DeviceToken::hashFor('fcm-token-abc'))->count());
    }

    public function test_these_endpoints_need_a_token(): void
    {
        $this->getJson('/api/v1/outlets')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->postJson('/api/v1/devices', ['token' => 'x'])->assertUnauthorized();
    }
}
