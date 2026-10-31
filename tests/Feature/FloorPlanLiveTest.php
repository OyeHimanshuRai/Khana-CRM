<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\FloorPlanInsights;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The floor plan's attention flags, booking line and layout lock.
 */
class FloorPlanLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        Carbon::setTestNow('2026-06-01 20:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CurrentShop::forget();

        parent::tearDown();
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function floor(): Floor
    {
        $floor = new Floor(['shop_id' => $this->shop()->id, 'name' => 'Rooftop', 'code' => 'RT', 'is_active' => true]);
        $floor->save();

        return $floor;
    }

    private function table(Floor $floor, string $name = '12', array $overrides = []): RestaurantTable
    {
        $table = new RestaurantTable(array_merge([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => $name,
            'code' => 'RT-'.$name,
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ], $overrides));
        $table->save();

        return $table;
    }

    private function seat(RestaurantTable $table, int $minutesAgo, string $status = TableSession::OPEN): TableSession
    {
        $session = new TableSession([
            'shop_id' => $table->shop_id,
            'restaurant_table_id' => $table->id,
            'token' => Str::random(40),
            'status' => $status,
            'covers' => 2,
            'opened_at' => now()->subMinutes($minutesAgo),
            'last_activity_at' => now()->subMinutes($minutesAgo),
        ]);
        $session->save();

        return $session;
    }

    private function booking(RestaurantTable $table, int $minutesFromNow, string $status = Reservation::CONFIRMED): Reservation
    {
        return Reservation::query()->create([
            'shop_id' => $table->shop_id,
            'restaurant_table_id' => $table->id,
            'guest_name' => 'Sharma Family',
            'party_size' => 4,
            'reserved_for' => now()->addMinutes($minutesFromNow),
            'duration_minutes' => 90,
            'status' => $status,
            'source' => 'phone',
        ]);
    }

    private function insights(RestaurantTable $table): array
    {
        $tables = RestaurantTable::query()->with('currentSession')->whereKey($table->id)->get();

        return app(FloorPlanInsights::class)->for($tables)[$table->id];
    }

    private function manager(bool $canAdjust = true): User
    {
        $user = User::create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Floor Manager',
            'email' => 'fm-'.Str::random(5).'@example.test',
            'password' => 'manager-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(array_filter([
            'dashboard.overview.view', 'dining.floors.view', 'dining.tables.view',
            $canAdjust ? 'dining.tables.adjust' : null,
        ]));
        $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $this->shop()->id, 'all_shops_view' => false])->save();

        return $user;
    }

    /* ----------------------------------------------------------- alerts */

    public function test_a_long_stay_is_flagged_but_a_short_one_is_not(): void
    {
        $floor = $this->floor();
        $long = $this->table($floor, '1');
        $short = $this->table($floor, '2');
        $this->seat($long, 95);
        $this->seat($short, 30);

        $this->assertSame([FloorPlanInsights::LONG_STAY], $this->insights($long)['alerts']);
        $this->assertSame([], $this->insights($short)['alerts']);
    }

    public function test_a_bill_left_waiting_is_flagged(): void
    {
        $table = $this->table($this->floor());
        $this->seat($table, 12, TableSession::BILLED);

        $this->assertSame([FloorPlanInsights::BILL_WAITING], $this->insights($table)['alerts']);
    }

    public function test_a_fresh_bill_is_not_flagged(): void
    {
        $table = $this->table($this->floor());
        $this->seat($table, 3, TableSession::BILLED);

        $this->assertSame([], $this->insights($table)['alerts']);
    }

    public function test_slow_cleaning_is_flagged(): void
    {
        $table = $this->table($this->floor(), '5', ['status' => RestaurantTable::CLEANING]);
        DB::table('restaurant_tables')->where('id', $table->id)->update(['updated_at' => now()->subMinutes(20)]);

        $this->assertSame([FloorPlanInsights::SLOW_CLEANING], $this->insights($table)['alerts']);
    }

    public function test_thresholds_come_from_config(): void
    {
        config(['dining.plan.long_stay_minutes' => 20]);
        $table = $this->table($this->floor());
        $this->seat($table, 30);

        $this->assertSame([FloorPlanInsights::LONG_STAY], $this->insights($table)['alerts']);
    }

    /* --------------------------------------------------------- bookings */

    public function test_a_booking_within_the_hour_shows_and_one_far_off_does_not(): void
    {
        $floor = $this->floor();
        $soon = $this->table($floor, '1');
        $later = $this->table($floor, '2');
        $this->booking($soon, 30);
        $this->booking($later, 180);

        $this->assertNotNull($this->insights($soon)['reservation']);
        $this->assertNull($this->insights($later)['reservation']);
    }

    public function test_a_guest_who_has_not_come_is_flagged_late_but_only_on_an_empty_table(): void
    {
        $floor = $this->floor();
        $empty = $this->table($floor, '1');
        $taken = $this->table($floor, '2');
        $this->booking($empty, -15);
        $this->booking($taken, -15);
        $this->seat($taken, 5);

        $this->assertContains(FloorPlanInsights::LATE_BOOKING, $this->insights($empty)['alerts']);
        $this->assertNotContains(FloorPlanInsights::LATE_BOOKING, $this->insights($taken)['alerts']);
    }

    public function test_a_cancelled_booking_is_ignored(): void
    {
        $table = $this->table($this->floor());
        $this->booking($table, 20, Reservation::CANCELLED);

        $this->assertNull($this->insights($table)['reservation']);
    }

    public function test_the_earliest_booking_wins_when_a_table_has_two(): void
    {
        $table = $this->table($this->floor());
        $this->booking($table, 50);
        $first = $this->booking($table, 10);

        $this->assertSame($first->id, $this->insights($table)['reservation']->id);
    }

    public function test_the_insights_cost_a_fixed_number_of_queries_however_many_tables(): void
    {
        $floor = $this->floor();
        foreach (range(1, 12) as $n) {
            $table = $this->table($floor, (string) $n);
            $this->booking($table, 20);
        }

        $tables = RestaurantTable::query()->with('currentSession')->get();

        DB::enableQueryLog();
        app(FloorPlanInsights::class)->for($tables);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
    }

    /* ------------------------------------------------------------- page */

    private function planQueryCount(): int
    {
        $user = $this->manager();
        $this->actingAs($user);
        $this->get('/superadmin/tables/plan')->assertOk(); // warm the permission and view caches

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/superadmin/tables/plan')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_plan_does_not_run_a_query_per_table(): void
    {
        $floor = $this->floor();
        $this->table($floor, '1');
        $this->table($floor, '2');
        $few = $this->planQueryCount();

        foreach (range(3, 14) as $n) {
            $table = $this->table($floor, (string) $n);
            $this->seat($table, 10);
            $this->booking($table, 20);
        }
        $many = $this->planQueryCount();

        $this->assertSame($few, $many, "2 tables ran {$few} queries but 14 ran {$many}");
    }

    public function test_tables_never_placed_are_spread_out_not_piled_in_the_corner(): void
    {
        $floor = $this->floor();
        foreach (range(1, 6) as $n) {
            $this->table($floor, (string) $n);
        }
        $placed = $this->table($floor, '7', ['pos_x' => 40, 'pos_y' => 55]);

        $positions = app(FloorPlanInsights::class)->positions(RestaurantTable::query()->orderBy('id')->get());

        $unplaced = collect($positions)->where('auto', true);
        $this->assertCount(6, $unplaced);
        $this->assertGreaterThan(1, $unplaced->pluck('x')->unique()->count());
        $this->assertSame(['x' => 40.0, 'y' => 55.0, 'auto' => false], $positions[$placed->id]);
        $this->assertTrue($unplaced->every(fn ($p) => $p['x'] >= 4 && $p['y'] >= 4));
        $this->assertTrue($unplaced->every(fn ($p) => $p['x'] >= 0 && $p['x'] <= 100 && $p['y'] >= 0 && $p['y'] <= 100));
    }

    public function test_the_plan_page_shows_the_attention_line_and_booking(): void
    {
        $floor = $this->floor();
        $table = $this->table($floor, '7');
        $this->seat($table, 100);
        $other = $this->table($floor, '8');
        $this->booking($other, 25);

        $this->actingAs($this->manager())->get('/superadmin/tables/plan')
            ->assertOk()
            ->assertSee('1 table needs attention')
            ->assertSee('Seated a long time')
            ->assertSee('Sharma Fam')
            ->assertSee('data-plan-lock-toggle', false)
            ->assertSee('data-plan-refresh="10"', false);
    }

    public function test_a_quiet_floor_says_so(): void
    {
        $this->table($this->floor());

        $this->actingAs($this->manager())->get('/superadmin/tables/plan')
            ->assertOk()->assertSee('Nothing needs attention right now.');
    }

    public function test_the_fragment_carries_the_flags_for_the_refresh(): void
    {
        $table = $this->table($this->floor());
        $this->seat($table, 100);

        $this->actingAs($this->manager())->get('/superadmin/tables/plan', ['X-Fragment' => '1'])
            ->assertOk()->assertSee('data-plan-attention="1"', false);
    }

    public function test_without_the_adjust_right_there_is_no_edit_button_or_drag(): void
    {
        $this->table($this->floor());

        $this->actingAs($this->manager(false))->get('/superadmin/tables/plan')
            ->assertOk()
            ->assertDontSee('data-plan-lock-toggle', false)
            ->assertDontSee('data-plan-editable', false);
    }

    public function test_the_server_still_refuses_a_move_without_the_right(): void
    {
        $table = $this->table($this->floor());

        $this->actingAs($this->manager(false))
            ->putJson(route('admin.tables.position', $table), ['pos_x' => 10, 'pos_y' => 10])
            ->assertForbidden();
    }
}
