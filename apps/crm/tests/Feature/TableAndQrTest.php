<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableQr;
use App\Models\User;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Dining areas, tables, the floor plan and the QR codes on them.
 *
 * The half worth testing hardest is the QR invariant: a table has at most one
 * live code, regenerating withdraws the old one in the same breath, and a
 * withdrawn code is kept rather than deleted so a printed sticker can be told
 * apart from one that never existed.
 */
class TableAndQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries a sub-path on this install, which would prefix
        // every test request and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ actors */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /** Somebody with every dining right, pinned to one branch. */
    private function manager(): User
    {
        $user = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Floor Manager',
            'email' => 'floormanager@example.test',
            'password' => 'manager-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo([
            'dashboard.overview.view',
            'dining.floors.view', 'dining.floors.create', 'dining.floors.edit', 'dining.floors.delete',
            'dining.tables.view', 'dining.tables.create', 'dining.tables.edit',
            'dining.tables.delete', 'dining.tables.adjust',
            'dining.qr.view', 'dining.qr.create', 'dining.qr.delete', 'dining.qr.print',
        ]);

        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);

        $user->forceFill([
            'current_shop_id' => $this->shop()->id,
            'all_shops_view' => false,
        ])->save();

        return $user;
    }

    /* ------------------------------------------------------------ fixtures */

    private function floor(array $overrides = []): Floor
    {
        $floor = new Floor(array_merge([
            'shop_id' => $this->shop()->id,
            'name' => 'Rooftop',
            'code' => 'RT',
            'is_active' => true,
        ], $overrides));

        $floor->save();

        return $floor;
    }

    private function table(?Floor $floor = null, array $overrides = []): RestaurantTable
    {
        $floor ??= $this->floor();

        $table = new RestaurantTable(array_merge([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '12',
            'code' => 'RT-12',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ], $overrides));

        $table->save();

        return $table;
    }

    /* ---------------------------------------------------------------- areas */

    public function test_a_dining_area_is_created_and_its_code_upper_cased(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/admin/floors', [
                'shop_id' => $this->shop()->id,
                'name' => 'Garden',
                'code' => 'gd',
                'is_active' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'GD');
    }

    public function test_two_branches_may_both_have_a_ground_floor(): void
    {
        $first = $this->shop();

        $second = Shop::query()->create([
            'tenant_id' => $first->tenant_id,
            'name' => 'Second Branch',
            'code' => 'TWO',
            'slug' => Shop::uniqueSlug('Second Branch'),
            'is_active' => true,
        ]);

        $this->floor(['name' => 'Ground Floor', 'code' => 'GF']);

        $duplicate = new Floor([
            'shop_id' => $second->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
            'is_active' => true,
        ]);

        // The unique index is composite, so this must not collide.
        $duplicate->save();

        $this->assertDatabaseCount('floors', 2);
    }

    public function test_a_duplicate_area_code_in_one_branch_is_refused(): void
    {
        $this->floor(['name' => 'Ground Floor', 'code' => 'GF']);

        $this->actingAs($this->manager())
            ->postJson('/admin/floors', [
                'shop_id' => $this->shop()->id,
                'name' => 'Garden',
                'code' => 'GF',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['code']]);
    }

    /**
     * Deleting an area would take its tables, their QR codes and the history
     * behind them. Refused rather than cascaded.
     */
    public function test_an_area_with_tables_cannot_be_deleted(): void
    {
        $floor = $this->floor();
        $this->table($floor);

        $this->actingAs($this->manager())
            ->deleteJson("/admin/floors/{$floor->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('floors', ['id' => $floor->id]);
    }

    public function test_an_area_cannot_be_closed_while_a_table_is_seated(): void
    {
        $floor = $this->floor();
        $this->table($floor, ['status' => RestaurantTable::OCCUPIED]);

        $this->actingAs($this->manager())
            ->putJson("/admin/floors/{$floor->id}/status")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue($floor->fresh()->is_active);
    }

    /* --------------------------------------------------------------- tables */

    /**
     * A table with no code is a table nobody can order from, so issuing is
     * not a second step somebody can forget.
     */
    public function test_creating_a_table_issues_its_qr_code(): void
    {
        $floor = $this->floor();

        $this->actingAs($this->manager())
            ->postJson('/admin/tables', [
                'shop_id' => $this->shop()->id,
                'floor_id' => $floor->id,
                'name' => '7',
                'code' => 'rt-07',
                'capacity' => 2,
                'is_active' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'RT-07');

        $table = RestaurantTable::allShops()->where('code', 'RT-07')->firstOrFail();

        $this->assertNotNull($table->activeQr);
        $this->assertSame(32, strlen($table->activeQr->token));
    }

    public function test_a_table_cannot_be_filed_onto_another_branches_area(): void
    {
        $other = Shop::query()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Other Branch',
            'code' => 'OTH',
            'slug' => Shop::uniqueSlug('Other Branch'),
            'is_active' => true,
        ]);

        $theirFloor = new Floor([
            'shop_id' => $other->id,
            'name' => 'Their Rooftop',
            'code' => 'TR',
            'is_active' => true,
        ]);
        $theirFloor->save();

        $this->actingAs($this->manager())
            ->postJson('/admin/tables', [
                'shop_id' => $this->shop()->id,
                'floor_id' => $theirFloor->id,
                'name' => '1',
                'code' => 'X-01',
                'capacity' => 4,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['floor_id']]);
    }

    public function test_the_next_code_continues_the_areas_series(): void
    {
        $floor = $this->floor(['code' => 'GF', 'name' => 'Ground Floor']);

        $this->assertSame('GF-01', RestaurantTable::nextCode($floor));

        $this->table($floor, ['name' => '1', 'code' => 'GF-01']);
        $this->table($floor, ['name' => '2', 'code' => 'GF-02']);

        $this->assertSame('GF-03', RestaurantTable::nextCode($floor));
    }

    public function test_the_status_is_set_from_the_floor_plan(): void
    {
        $table = $this->table();

        $this->actingAs($this->manager())
            ->putJson("/admin/tables/{$table->id}/status", [
                'status' => RestaurantTable::OCCUPIED,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', RestaurantTable::OCCUPIED);

        $this->assertSame(RestaurantTable::OCCUPIED, $table->fresh()->status);
    }

    /** A note belongs to the state it was written for. */
    public function test_a_status_change_without_a_note_clears_the_old_one(): void
    {
        $table = $this->table(null, ['status' => RestaurantTable::RESERVED, 'note' => 'Booked 8pm']);

        $this->actingAs($this->manager())
            ->putJson("/admin/tables/{$table->id}/status", [
                'status' => RestaurantTable::OCCUPIED,
            ])
            ->assertOk();

        $this->assertNull($table->fresh()->note);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $table = $this->table();

        $this->actingAs($this->manager())
            ->putJson("/admin/tables/{$table->id}/status", ['status' => 'on_fire'])
            ->assertStatus(422);

        $this->assertSame(RestaurantTable::AVAILABLE, $table->fresh()->status);
    }

    public function test_a_position_off_the_canvas_is_refused(): void
    {
        $table = $this->table();

        $this->actingAs($this->manager())
            ->putJson("/admin/tables/{$table->id}/position", ['pos_x' => 4000, 'pos_y' => 10])
            ->assertStatus(422);

        $this->assertSame(0.0, (float) $table->fresh()->pos_x);
    }

    public function test_a_position_is_saved_as_a_percentage(): void
    {
        $table = $this->table();

        $this->actingAs($this->manager())
            ->putJson("/admin/tables/{$table->id}/position", ['pos_x' => 33.333, 'pos_y' => 66.667])
            ->assertOk();

        $this->assertSame(33.333, (float) $table->fresh()->pos_x);
        $this->assertSame(66.667, (float) $table->fresh()->pos_y);
    }

    public function test_a_seated_table_cannot_be_deleted(): void
    {
        $table = $this->table(null, ['status' => RestaurantTable::BILLING]);

        $this->actingAs($this->manager())
            ->deleteJson("/admin/tables/{$table->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('restaurant_tables', ['id' => $table->id]);
    }

    /** Nothing can resolve the code once the table is gone. */
    public function test_deleting_a_table_withdraws_its_code(): void
    {
        $table = $this->table();
        app(TableQrService::class)->issue($table);

        $this->actingAs($this->manager())
            ->deleteJson("/admin/tables/{$table->id}")
            ->assertOk();

        $this->assertSame(0, TableQr::allShops()->whereNull('revoked_at')->count());
    }

    /* ------------------------------------------------------------------ qr */

    public function test_a_table_has_at_most_one_live_code(): void
    {
        $table = $this->table();
        $service = app(TableQrService::class);

        $first = $service->issue($table);
        $second = $service->issue($table);
        $third = $service->issue($table);

        $this->assertNotSame($first->token, $second->token);
        $this->assertNotSame($second->token, $third->token);

        $live = TableQr::allShops()
            ->where('restaurant_table_id', $table->id)
            ->whereNull('revoked_at')
            ->get();

        $this->assertCount(1, $live);
        $this->assertSame($third->token, $live->first()->token);
    }

    /**
     * Revoked, not deleted: a printed sticker outlives the row, and a scan of
     * a withdrawn code has to be tellable from one that never existed.
     */
    public function test_a_replaced_code_is_kept_with_its_reason(): void
    {
        $table = $this->table();
        $service = app(TableQrService::class);

        $old = $service->issue($table);
        $service->issue($table, 'Sticker was photographed');

        $old->refresh();

        $this->assertNotNull($old->revoked_at);
        $this->assertSame('Sticker was photographed', $old->revoked_reason);
        $this->assertDatabaseCount('table_qrs', 2);
    }

    public function test_regenerating_over_http_invalidates_the_old_code(): void
    {
        $table = $this->table();
        $original = app(TableQrService::class)->issue($table)->token;

        $this->actingAs($this->manager())
            ->postJson("/admin/qr/{$table->id}/regenerate", ['reason' => 'Reprinting'])
            ->assertOk();

        $this->assertNotSame($original, $table->fresh()->activeQr->token);
        $this->assertDatabaseHas('table_qrs', ['token' => $original, 'revoked_reason' => 'Reprinting']);
    }

    public function test_withdrawing_leaves_the_table_with_no_code(): void
    {
        $table = $this->table();
        app(TableQrService::class)->issue($table);

        $this->actingAs($this->manager())
            ->deleteJson("/admin/qr/{$table->id}", ['reason' => 'Table out of service'])
            ->assertOk();

        $this->assertNull($table->fresh()->activeQr);
    }

    public function test_withdrawing_a_table_that_has_no_code_says_so(): void
    {
        $table = $this->table();
        app(TableQrService::class)->revoke($table);

        $this->actingAs($this->manager())
            ->deleteJson("/admin/qr/{$table->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * The bulk action fills the gaps and leaves the rest alone. Re-issuing
     * every code in the restaurant would be a very expensive misclick.
     */
    public function test_issuing_the_missing_ones_leaves_existing_codes_alone(): void
    {
        $floor = $this->floor();

        $coded = $this->table($floor, ['name' => '1', 'code' => 'RT-01']);
        $token = app(TableQrService::class)->issue($coded)->token;

        $this->table($floor, ['name' => '2', 'code' => 'RT-02']);
        $this->table($floor, ['name' => '3', 'code' => 'RT-03']);

        $this->actingAs($this->manager())
            ->postJson('/admin/qr/issue-missing')
            ->assertOk()
            ->assertJsonPath('data.issued', 2);

        $this->assertSame($token, $coded->fresh()->activeQr->token);
        $this->assertSame(3, TableQr::allShops()->whereNull('revoked_at')->count());
    }

    public function test_a_table_out_of_service_is_not_given_a_code_in_bulk(): void
    {
        $floor = $this->floor();
        $this->table($floor, ['name' => '9', 'code' => 'RT-09', 'is_active' => false]);

        $this->actingAs($this->manager())
            ->postJson('/admin/qr/issue-missing')
            ->assertOk();

        $this->assertSame(0, TableQr::allShops()->whereNull('revoked_at')->count());
    }

    /* --------------------------------------------------------------- screens */

    public function test_the_screens_render(): void
    {
        $floor = $this->floor();
        $table = $this->table($floor);
        app(TableQrService::class)->issue($table);

        $manager = $this->manager();

        $this->actingAs($manager)->get('/admin/floors')->assertOk()->assertSee('Rooftop');
        $this->actingAs($manager)->get('/admin/tables')->assertOk()->assertSee('RT-12');
        $this->actingAs($manager)->get('/admin/tables/plan')->assertOk()->assertSee('data-floor-plan', false);
        $this->actingAs($manager)->get('/admin/qr')->assertOk()->assertSee('RT-12');
    }

    /** The sheet is a real QR, rendered server-side, not a placeholder. */
    public function test_the_print_sheet_carries_a_scannable_code(): void
    {
        $table = $this->table();
        $qr = app(TableQrService::class)->issue($table);

        $html = $this->actingAs($this->manager())
            ->get('/admin/qr/sheet/print')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Table 12', $html);
        // The toolbar is screen-only, so the sheet must not be framed by the
        // admin layout - a sidebar on the paper would be worse than useless.
        $this->assertStringNotContainsString('app-sidebar', $html);
        $this->assertNotEmpty($qr->url());
    }

    /**
     * A table with no live code is left off the sheet rather than printed
     * blank: somebody will stick an empty square on a table.
     */
    public function test_a_table_without_a_code_is_left_off_the_sheet(): void
    {
        $floor = $this->floor();
        $coded = $this->table($floor, ['name' => '1', 'code' => 'RT-01']);
        app(TableQrService::class)->issue($coded);

        $this->table($floor, ['name' => '2', 'code' => 'RT-02']);

        $html = $this->actingAs($this->manager())
            ->get('/admin/qr/sheet/print')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('RT-01', $html);
        $this->assertStringNotContainsString('RT-02', $html);
    }

    /* ----------------------------------------------------------- permissions */

    public function test_a_captain_may_seat_a_table_but_not_rename_the_restaurant(): void
    {
        $table = $this->table();

        $captain = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Captain',
            'email' => 'captain@example.test',
            'password' => 'captain-password-1',
            'is_admin' => true,
        ]);

        // The floor's rights, and nothing that reshapes it.
        $captain->givePermissionTo(['dining.tables.view', 'dining.tables.adjust']);
        $captain->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $captain->forceFill(['current_shop_id' => $this->shop()->id])->save();

        $this->actingAs($captain)
            ->putJson("/admin/tables/{$table->id}/status", ['status' => RestaurantTable::OCCUPIED])
            ->assertOk();

        $this->actingAs($captain)
            ->putJson("/admin/tables/{$table->id}", [
                'floor_id' => $table->floor_id,
                'name' => 'Renamed',
                'code' => 'RT-99',
                'capacity' => 4,
            ])
            ->assertForbidden();
    }

    public function test_a_viewer_cannot_regenerate_a_code(): void
    {
        $table = $this->table();
        app(TableQrService::class)->issue($table);

        $viewer = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'QR Viewer',
            'email' => 'qrviewer@example.test',
            'password' => 'viewer-password-1',
            'is_admin' => true,
        ]);

        $viewer->givePermissionTo(['dining.qr.view']);
        $viewer->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $viewer->forceFill(['current_shop_id' => $this->shop()->id])->save();

        $this->actingAs($viewer)
            ->postJson("/admin/qr/{$table->id}/regenerate")
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/admin/tables')->assertRedirect('http://localhost/admin/login');
        $this->get('/admin/qr')->assertRedirect('http://localhost/admin/login');
    }

    /* -------------------------------------------------------------- tenancy */

    /** The shop scope is the default, so another branch's tables are invisible. */
    public function test_another_branches_tables_are_not_listed(): void
    {
        $mine = $this->table($this->floor(), ['name' => '1', 'code' => 'RT-01']);

        $other = Shop::query()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Far Branch',
            'code' => 'FAR',
            'slug' => Shop::uniqueSlug('Far Branch'),
            'is_active' => true,
        ]);

        $theirFloor = new Floor([
            'shop_id' => $other->id, 'name' => 'Their Hall', 'code' => 'TH', 'is_active' => true,
        ]);
        $theirFloor->save();

        $theirTable = new RestaurantTable([
            'shop_id' => $other->id,
            'floor_id' => $theirFloor->id,
            'name' => '1',
            'code' => 'TH-01',
            'capacity' => 4,
            'is_active' => true,
        ]);
        $theirTable->save();

        $this->actingAs($this->manager())
            ->get('/admin/tables')
            ->assertOk()
            ->assertSee($mine->code)
            ->assertDontSee($theirTable->code);
    }
}
