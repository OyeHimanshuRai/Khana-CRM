<?php

namespace Tests\Feature;

use App\Http\Controllers\TableScanController;
use App\Models\Floor;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableQrService;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What happens when a phone scans the sticker on a table (§3.3 - §3.7).
 *
 * The only unauthenticated route in the app that opens a record, so the
 * things worth testing hardest are the ones that follow from that: no tenant
 * in context, a withdrawn code told apart from one that never existed, and
 * one sitting per table however many people scan at once.
 */
class TableScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    /** Somebody who may read the floor but is not the seeded super admin. */
    private function staff(): User
    {
        $user = User::query()->firstWhere('email', 'floorstaff@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Floor Staff',
                'email' => 'floorstaff@example.test',
                'password' => 'staff-password-1',
                'is_admin' => true,
            ]);

            $user->givePermissionTo(['dining.tables.view', 'dining.tables.adjust']);
            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill(['current_shop_id' => $this->shop()->id])->save();
        }

        return $user;
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function floor(array $overrides = []): Floor
    {
        $floor = new Floor(array_merge([
            'shop_id' => $this->shop()->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
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
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ], $overrides));

        $table->save();

        return $table;
    }

    /** A table with a live code on it, as the create screen would leave it. */
    private function coded(?Floor $floor = null, array $overrides = []): RestaurantTable
    {
        $table = $this->table($floor, $overrides);
        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    /* ---------------------------------------------------------------- scan */

    public function test_a_scan_seats_the_party_and_redirects_off_the_token(): void
    {
        $table = $this->coded();

        // The token must never stay in the address bar: it is printed on the
        // table and a screenshot of it is a way onto somebody else's bill.
        $this->get('/t/'.$table->activeQr->token)
            ->assertRedirect(route('table.show'));

        $this->assertSame(1, TableSession::allShops()->count());
    }

    public function test_the_guest_page_names_the_table(): void
    {
        $table = $this->coded();

        $this->get('/t/'.$table->activeQr->token);

        $this->get('/t')
            ->assertOk()
            ->assertSee('Table 4')
            ->assertSee('Seated');
    }

    /**
     * §3.11: later orders from the same table join the same bill. That only
     * works if a second scan finds the sitting rather than starting one.
     */
    public function test_scanning_twice_does_not_start_a_second_sitting(): void
    {
        $table = $this->coded();
        $token = $table->activeQr->token;

        $this->get('/t/'.$token)->assertRedirect(route('table.show'));
        $this->get('/t/'.$token)->assertRedirect(route('table.show'));

        $this->assertSame(1, TableSession::allShops()->count());
    }

    /**
     * Three friends at one table all scanning the same sticker must land in
     * one sitting, not three - otherwise the table gets three bills and the
     * waiter gets an argument.
     */
    public function test_separate_phones_at_one_table_share_the_sitting(): void
    {
        $table = $this->coded();
        $token = $table->activeQr->token;

        $this->get('/t/'.$token);

        // A second device: no shared cookie jar.
        $this->flushSession();
        $this->get('/t/'.$token);

        $this->assertSame(1, TableSession::allShops()->count());
    }

    public function test_a_scan_marks_the_table_occupied(): void
    {
        $table = $this->coded(null, ['status' => RestaurantTable::AVAILABLE]);

        $this->get('/t/'.$table->activeQr->token);

        $this->assertSame(RestaurantTable::OCCUPIED, $table->fresh()->status);
    }

    /** The party with the booking has arrived - that is what a scan means. */
    public function test_a_scan_promotes_a_reserved_table(): void
    {
        $table = $this->coded(null, ['status' => RestaurantTable::RESERVED]);

        $this->get('/t/'.$table->activeQr->token);

        $this->assertSame(RestaurantTable::OCCUPIED, $table->fresh()->status);
    }

    /**
     * A party mid-settlement re-scanning must not tell the floor the bill was
     * cancelled.
     */
    public function test_a_scan_does_not_reopen_a_table_being_billed(): void
    {
        $table = $this->coded(null, ['status' => RestaurantTable::BILLING]);

        $this->get('/t/'.$table->activeQr->token);

        $this->assertSame(RestaurantTable::BILLING, $table->fresh()->status);
    }

    public function test_the_scan_is_counted(): void
    {
        $table = $this->coded();

        $this->get('/t/'.$table->activeQr->token);
        $this->get('/t/'.$table->activeQr->token);

        $this->assertSame(2, $table->fresh()->activeQr->scan_count);
    }

    /* ----------------------------------------------------------- dead ends */

    /**
     * A sticker outlives the row it came from, so a withdrawn code gets an
     * explanation. Telling a guest "no such table" when the real answer is
     * "we reprinted these last week" sends them to complain about the wrong
     * thing.
     */
    public function test_a_withdrawn_code_explains_itself_rather_than_404ing(): void
    {
        $table = $this->coded();
        $old = $table->activeQr->token;

        app(TableQrService::class)->issue($table, 'Reprinted');

        $this->get('/t/'.$old)
            ->assertRedirect(route('table.retired'));

        $this->get('/t/code-replaced')
            ->assertOk()
            ->assertSee('This code has been replaced');

        // And nothing was seated on the strength of a dead sticker.
        $this->assertSame(0, TableSession::allShops()->count());
    }

    public function test_a_made_up_token_is_a_404(): void
    {
        $this->get('/t/'.str_repeat('z', 32))->assertNotFound();
    }

    /** The route constraint, so a probe never reaches the controller. */
    public function test_a_token_of_the_wrong_shape_is_a_404(): void
    {
        $this->get('/t/short')->assertNotFound();
        $this->get('/t/'.str_repeat('z', 40))->assertNotFound();
    }

    public function test_a_table_out_of_service_takes_no_orders(): void
    {
        $table = $this->coded(null, ['is_active' => false]);

        $this->get('/t/'.$table->activeQr->token)
            ->assertRedirect(route('table.closed'));

        $this->assertSame(0, TableSession::allShops()->count());
    }

    /** The rooftop shuts in the monsoon and its stickers stay on the tables. */
    public function test_a_closed_dining_area_takes_no_orders(): void
    {
        $floor = $this->floor(['name' => 'Rooftop', 'code' => 'RT', 'is_active' => false]);
        $table = $this->coded($floor, ['code' => 'RT-01', 'name' => '1']);

        $this->get('/t/'.$table->activeQr->token)
            ->assertRedirect(route('table.closed'));
    }

    public function test_a_suspended_branch_takes_no_orders(): void
    {
        $table = $this->coded();

        $this->shop()->forceFill(['is_active' => false])->save();

        $this->get('/t/'.$table->activeQr->token)
            ->assertRedirect(route('table.closed'));
    }

    public function test_the_guest_page_without_a_session_sends_them_back_to_the_sticker(): void
    {
        $this->get('/t')->assertRedirect(route('table.expired'));

        $this->get('/t/session-ended')
            ->assertOk()
            ->assertSee('Your session has ended');
    }

    public function test_a_closed_sitting_does_not_resurrect(): void
    {
        $table = $this->coded();

        $this->get('/t/'.$table->activeQr->token);

        $session = TableSession::allShops()->firstOrFail();
        app(TableSessionService::class)->close($session, 'Bill settled');

        // The cookie is still in the browser, but the sitting is over.
        $this->get('/t')->assertRedirect(route('table.expired'));
    }

    /**
     * The loop closed: a guest scans, and the floor sees it without anybody
     * having to tell them.
     */
    public function test_the_floor_plan_shows_the_party_once_they_scan(): void
    {
        $table = $this->coded();

        $this->actingAs($this->staff())
            ->get('/admin/tables/plan')
            ->assertOk()
            ->assertDontSee('plan-table-since', false);

        // A different device: the guest, with no admin session.
        $this->flushSession();
        $this->get('/t/'.$table->activeQr->token);

        $this->actingAs($this->staff())
            ->get('/admin/tables/plan')
            ->assertOk()
            ->assertSee('plan-table-since', false);
    }

    /* --------------------------------------------------------- the service */

    public function test_billing_a_sitting_stops_it_taking_orders(): void
    {
        $table = $this->table();
        $service = app(TableSessionService::class);

        $session = $service->openFor($table);
        $service->bill($session);

        $this->assertSame(TableSession::BILLED, $session->fresh()->status);
        $this->assertSame(RestaurantTable::BILLING, $table->fresh()->status);

        // Billed is not live: adding to a bill already printed would have the
        // guest pay for something the total did not include.
        $this->assertSame(0, TableSession::allShops()->live()->count());
    }

    /**
     * A party has just left. Offering the table to the next guests before
     * anybody has wiped it down would be lying about the room.
     */
    public function test_closing_a_sitting_leaves_the_table_to_be_cleared(): void
    {
        $table = $this->table();
        $service = app(TableSessionService::class);

        $session = $service->openFor($table);
        $service->close($session, 'Bill settled');

        $this->assertSame(RestaurantTable::CLEANING, $table->fresh()->status);
        $this->assertNotNull($session->fresh()->closed_at);
    }

    public function test_closing_twice_is_harmless(): void
    {
        $table = $this->table();
        $service = app(TableSessionService::class);

        $session = $service->openFor($table);
        $service->close($session, 'First');
        $closedAt = $session->fresh()->closed_at;

        $service->close($session->fresh(), 'Second');

        $this->assertSame(
            $closedAt->toDateTimeString(),
            $session->fresh()->closed_at->toDateTimeString(),
        );
        $this->assertSame('First', $session->fresh()->closed_reason);
    }

    /** After a sitting closes, the next scan is a new party. */
    public function test_the_next_party_gets_its_own_sitting(): void
    {
        $table = $this->coded();
        $token = $table->activeQr->token;

        $this->get('/t/'.$token);
        $first = TableSession::allShops()->firstOrFail();

        app(TableSessionService::class)->close($first, 'Bill settled');

        $this->flushSession();
        $this->get('/t/'.$token);

        $this->assertSame(2, TableSession::allShops()->count());
        $this->assertNotSame(
            $first->token,
            TableSession::allShops()->live()->firstOrFail()->token,
        );
    }

    /* --------------------------------------------------------------- guard */

    /**
     * The session token identifies one party's bill, so it lives in their own
     * cookie and never in a URL a screenshot could carry.
     */
    public function test_the_session_token_is_never_in_the_url(): void
    {
        $table = $this->coded();

        $this->get('/t/'.$table->activeQr->token);

        $session = TableSession::allShops()->firstOrFail();

        $html = $this->get('/t')->assertOk()->getContent();

        $this->assertStringNotContainsString($session->token, $html);
        $this->assertSame($session->token, session(TableScanController::SESSION_KEY));
    }

    /** No tenant is resolved for a guest, so the token has to carry the branch. */
    public function test_a_scan_resolves_the_branch_from_the_token_alone(): void
    {
        $other = Shop::query()->create([
            'tenant_id' => $this->shop()->tenant_id,
            'name' => 'Second Branch',
            'code' => 'TWO',
            'slug' => Shop::uniqueSlug('Second Branch'),
            'is_active' => true,
        ]);

        $theirFloor = new Floor([
            'shop_id' => $other->id, 'name' => 'Their Hall', 'code' => 'TH', 'is_active' => true,
        ]);
        $theirFloor->save();

        $theirTable = $this->coded($theirFloor, ['code' => 'TH-01', 'name' => '1']);

        $this->get('/t/'.$theirTable->activeQr->token)->assertRedirect(route('table.show'));

        $session = TableSession::allShops()->firstOrFail();

        $this->assertSame($other->id, $session->shop_id);
    }
}
