<?php

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\ReservationService;
use App\Services\TableSessionService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * The evening's book (§7, §21).
 *
 * Almost everything a booking system gets wrong can be fixed at the door
 * with an apology. Double-booking cannot: two parties arrive, both were
 * promised, and one of them is going home. So most of what follows is about
 * the overlap check and the exact edges of it.
 *
 * The second theme is that the system never decides what a person knows. It
 * can say a booking is overdue; only a host can say nobody came.
 */
class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        CurrentShop::forget();

        // Every test in this file writes shop-scoped rows, so there has to be
        // a shop in context. Tests that care about permissions sign somebody
        // else in over the top.
        $this->actingAs($this->staff());
        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ---------------------------------------------------------- fixtures */

    private function service(): ReservationService
    {
        return app(ReservationService::class);
    }

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    /**
     * A host, attached to one branch.
     *
     * The service stamps shop_id from CurrentShop, which resolves off the
     * signed-in user - so a service-level test has to sign somebody in even
     * when it never touches a route. Same fixture shape as WastageTest.
     *
     * @param  array<int, string>  $permissions
     */
    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'host@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Front of House',
                'email' => 'host@example.test',
                'password' => 'host-password-1',
                'is_admin' => true,
            ]);

            $user->shops()->syncWithoutDetaching([$this->shop()->id => ['is_default' => true]]);
            $user->forceFill([
                'current_shop_id' => $this->shop()->id,
                'all_shops_view' => false,
            ])->save();
        }

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    private function table(string $name = '4', int $capacity = 4): RestaurantTable
    {
        $floor = Floor::firstOrCreate(
            ['shop_id' => $this->shop()->id, 'code' => 'GF'],
            ['name' => 'Ground Floor', 'is_active' => true],
        );

        return RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => $name,
            'code' => 'GF-'.$name,
            'capacity' => $capacity,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function book(array $overrides = []): Reservation
    {
        return $this->service()->create(array_merge([
            'guest_name' => 'Mehta',
            'guest_mobile' => '9876543210',
            'party_size' => 4,
            'reserved_for' => Carbon::today()->setTime(20, 0),
            'duration_minutes' => 90,
        ], $overrides));
    }

    /* ---------------------------------------------------- double-booking */

    public function test_a_table_cannot_be_promised_to_two_parties_at_once(): void
    {
        $table = $this->table();

        $this->book(['restaurant_table_id' => $table->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already promised to Mehta');

        $this->book([
            'guest_name' => 'Rao',
            'restaurant_table_id' => $table->id,
            'reserved_for' => Carbon::today()->setTime(20, 30),
        ]);
    }

    public function test_back_to_back_sittings_are_allowed(): void
    {
        $table = $this->table();

        // 8:00–9:30 then 9:30–11:00. Touching windows do not overlap, and if
        // they did no restaurant could turn a table twice in an evening.
        $this->book(['restaurant_table_id' => $table->id]);

        $second = $this->book([
            'guest_name' => 'Rao',
            'restaurant_table_id' => $table->id,
            'reserved_for' => Carbon::today()->setTime(21, 30),
        ]);

        $this->assertSame(Reservation::CONFIRMED, $second->status);
        $this->assertSame(2, Reservation::query()->count());
    }

    public function test_a_booking_that_ends_one_minute_into_another_is_refused(): void
    {
        $table = $this->table();

        $this->book(['restaurant_table_id' => $table->id]);

        // 6:31–8:01 runs a minute past the start of the 8:00 booking.
        $this->expectException(RuntimeException::class);

        $this->book([
            'guest_name' => 'Rao',
            'restaurant_table_id' => $table->id,
            'reserved_for' => Carbon::today()->setTime(18, 31),
            'duration_minutes' => 90,
        ]);
    }

    public function test_a_booking_with_no_table_clashes_with_nobody(): void
    {
        $table = $this->table();
        $this->book(['restaurant_table_id' => $table->id]);

        // Most bookings are taken without deciding the table, and two of
        // those at the same time is an ordinary Friday.
        $one = $this->book(['guest_name' => 'Rao']);
        $two = $this->book(['guest_name' => 'Iyer']);

        $this->assertNull($one->restaurant_table_id);
        $this->assertNull($two->restaurant_table_id);
        $this->assertSame(3, Reservation::query()->count());
    }

    public function test_a_cancelled_booking_frees_the_table(): void
    {
        $table = $this->table();
        $first = $this->book(['restaurant_table_id' => $table->id]);

        $this->service()->cancel($first, 'rang to cancel');

        // A resolved booking holds nothing.
        $second = $this->book([
            'guest_name' => 'Rao',
            'restaurant_table_id' => $table->id,
        ]);

        $this->assertSame(Reservation::CONFIRMED, $second->status);
    }

    public function test_a_no_show_frees_the_table(): void
    {
        $table = $this->table();
        $first = $this->book(['restaurant_table_id' => $table->id]);

        $this->service()->noShow($first, 'no answer');

        $this->book(['guest_name' => 'Walk-in', 'restaurant_table_id' => $table->id]);

        $this->assertSame(2, Reservation::query()->count());
    }

    public function test_editing_a_booking_onto_a_busy_table_is_refused(): void
    {
        $table = $this->table();
        $this->book(['restaurant_table_id' => $table->id]);

        $other = $this->book(['guest_name' => 'Rao']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already promised');

        $this->service()->update($other, ['restaurant_table_id' => $table->id]);
    }

    public function test_a_booking_does_not_clash_with_itself_when_edited(): void
    {
        $table = $this->table();
        $booking = $this->book(['restaurant_table_id' => $table->id]);

        // Moving it half an hour must not be refused because it overlaps the
        // row being moved.
        $moved = $this->service()->update($booking, [
            'reserved_for' => Carbon::today()->setTime(20, 30)->toDateTimeString(),
        ]);

        $this->assertSame('20:30', $moved->reserved_for->format('H:i'));
    }

    /* ---------------------------------------------------------- seating */

    public function test_seating_a_party_opens_a_sitting_and_carries_their_details(): void
    {
        $table = $this->table();
        $booking = $this->book(['restaurant_table_id' => $table->id]);

        $seated = $this->service()->seat($booking);

        $this->assertSame(Reservation::SEATED, $seated->status);
        $this->assertNotNull($seated->table_session_id);

        $session = TableSession::allShops()->findOrFail($seated->table_session_id);

        $this->assertSame('Mehta', $session->guest_name);
        $this->assertSame('9876543210', $session->guest_mobile);
        $this->assertSame(4, $session->covers);
    }

    public function test_seating_reuses_a_sitting_the_party_already_opened(): void
    {
        $table = $this->table();
        $booking = $this->book(['restaurant_table_id' => $table->id]);

        // They scanned the QR on the way in.
        $existing = app(TableSessionService::class)->openFor($table);

        $seated = $this->service()->seat($booking);

        // One sitting, one bill. Opening a second would split their meal
        // across two bills, which §3.11 says must not happen.
        $this->assertSame($existing->id, $seated->table_session_id);
        $this->assertSame(1, TableSession::allShops()->where('restaurant_table_id', $table->id)->count());
    }

    public function test_seating_never_takes_over_another_partys_open_bill(): void
    {
        $table = $this->table();

        /*
         | The worst thing this service can do, and it used to do it silently.
         |
         | A table with a party at it has an open, unpaid bill on it. Reusing
         | that sitting for a new booking renamed somebody else's tab: the
         | timer, the rounds already fired and the money owed all stayed, and
         | the new guest's name went on top. The host was told the seating had
         | worked. Nothing anywhere said two parties were now one bill.
         */
        $eating = app(TableSessionService::class)->openFor($table);
        $eating->orders()->create([
            'shop_id' => $this->shop()->id,
            'order_number' => 'GF-4/1',
            'order_type' => Order::DINE_IN,
            'status' => Order::PENDING,
            'subtotal' => 520,
            'grand_total' => 520,
            'placed_at' => now(),
        ]);

        $booking = $this->book(['guest_name' => 'Sharma']);

        try {
            $this->service()->seat($booking, $table);
            $this->fail('A booking was seated onto a table with somebody else eating at it.');
        } catch (RuntimeException $e) {
            // Named and costed, because the host is standing at the door with
            // the new party and has to know what to do next.
            $this->assertStringContainsString('not free', $e->getMessage());
            $this->assertStringContainsString('Guest', $e->getMessage());
        }

        $this->assertSame(Reservation::CONFIRMED, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->table_session_id);

        // And the sitting it refused to touch is exactly as it was.
        $this->assertNull($eating->fresh()->guest_name);
    }

    public function test_seating_refuses_a_sitting_nobody_ever_closed(): void
    {
        $table = $this->table();

        $ghost = app(TableSessionService::class)->openFor($table);
        $ghost->forceFill(['opened_at' => now()->subDays(2)])->save();

        $booking = $this->book();

        // Nothing was ordered on it, so absorbing it would cost nothing - but
        // the floor plan has been calling that table occupied for two days,
        // and somebody may well be at it. See ReservationService::HANDOVER_MINUTES.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('with nothing ordered on it');

        $this->service()->seat($booking, $table);
    }

    public function test_a_table_somebody_is_sitting_at_cannot_be_promised_for_now(): void
    {
        $table = $this->table();
        app(TableSessionService::class)->openFor($table);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sitting at that table now');

        $this->book([
            'restaurant_table_id' => $table->id,
            'reserved_for' => now(),
        ]);
    }

    public function test_a_table_somebody_is_sitting_at_can_still_be_promised_for_later(): void
    {
        $table = $this->table();
        app(TableSessionService::class)->openFor($table);

        // A lunch party that has not left says nothing about nine o'clock, and
        // a book that refused the whole evening over it would be useless.
        $booking = $this->book([
            'restaurant_table_id' => $table->id,
            'reserved_for' => now()->addHours(5),
        ]);

        $this->assertSame($table->id, $booking->restaurant_table_id);
    }

    public function test_free_tables_leave_out_the_ones_with_people_at_them(): void
    {
        $free = $this->table('4');
        $busy = $this->table('7', 4);

        app(TableSessionService::class)->openFor($busy);

        $offered = $this->service()->availableTables(now(), 90, 2)->pluck('id');

        $this->assertTrue($offered->contains($free->id));
        $this->assertFalse($offered->contains($busy->id));
    }

    public function test_a_booking_with_no_table_cannot_be_seated_without_one(): void
    {
        $booking = $this->book();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Choose a table');

        $this->service()->seat($booking);
    }

    public function test_a_party_can_be_seated_somewhere_other_than_they_booked(): void
    {
        $booked = $this->table('4');
        $actual = $this->table('7', 6);

        $booking = $this->book(['restaurant_table_id' => $booked->id]);

        $seated = $this->service()->seat($booking, $actual);

        $this->assertSame($actual->id, $seated->restaurant_table_id);
    }

    public function test_somebody_sitting_down_is_not_a_no_show(): void
    {
        $table = $this->table();
        $booking = $this->book(['restaurant_table_id' => $table->id]);
        $this->service()->seat($booking);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a no-show');

        $this->service()->noShow($booking->fresh());
    }

    /* --------------------------------------------------------- the clock */

    public function test_overdue_is_a_prompt_and_never_a_decision(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(19, 0));

        $booking = $this->book(['reserved_for' => Carbon::today()->setTime(20, 0)]);

        $this->assertFalse($booking->isOverdue());

        // Twenty-five minutes past, with the twenty-minute grace gone.
        Carbon::setTestNow(Carbon::today()->setTime(20, 25));

        $this->assertTrue($booking->fresh()->isOverdue());

        // And still exactly as it was. Nothing decided they did not come.
        $this->assertSame(Reservation::CONFIRMED, $booking->fresh()->status);
    }

    public function test_a_seated_booking_is_never_overdue(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(19, 0));

        $table = $this->table();
        $booking = $this->book(['restaurant_table_id' => $table->id]);
        $this->service()->seat($booking);

        Carbon::setTestNow(Carbon::today()->setTime(22, 0));

        $this->assertFalse($booking->fresh()->isOverdue());
    }

    /* --------------------------------------------------------- the floor */

    public function test_free_tables_exclude_the_busy_ones_and_the_small_ones(): void
    {
        $big = $this->table('10', 8);
        $small = $this->table('2', 2);
        $taken = $this->table('5', 6);

        $at = Carbon::today()->setTime(20, 0);
        $this->book(['restaurant_table_id' => $taken->id, 'reserved_for' => $at]);

        $free = $this->service()->availableTables($at, 90, partySize: 4);

        $this->assertTrue($free->contains('id', $big->id));
        $this->assertFalse($free->contains('id', $small->id), 'a table for two was offered to a party of four');
        $this->assertFalse($free->contains('id', $taken->id), 'a promised table was offered again');
    }

    public function test_a_tables_next_booking_is_found_without_a_stored_flag(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(19, 0));

        $table = $this->table();
        $this->book(['restaurant_table_id' => $table->id, 'reserved_for' => Carbon::today()->setTime(20, 0)]);

        $next = $this->service()->nextBookingFor($table);

        // Nothing writes "reserved" onto the table row: a table is reserved
        // only for a window, and a stored flag would need something to run
        // to clear it.
        $this->assertNotNull($next);
        $this->assertSame('Mehta', $next->guest_name);
        $this->assertSame(RestaurantTable::AVAILABLE, $table->fresh()->status);
    }

    /* -------------------------------------------------------- the screens */

    public function test_the_book_shows_one_day_at_a_time(): void
    {
        $this->actingAs($this->admin());

        $this->book(['guest_name' => 'Tonight']);
        $this->book(['guest_name' => 'Tomorrow', 'reserved_for' => Carbon::tomorrow()->setTime(20, 0)]);

        $this->get(route('admin.reservations.index'))
            ->assertOk()
            ->assertSee('Tonight')
            ->assertDontSee('Tomorrow');

        $this->get(route('admin.reservations.index', ['day' => Carbon::tomorrow()->toDateString()]))
            ->assertOk()
            ->assertSee('Tomorrow');
    }

    public function test_a_nonsense_day_falls_back_to_today_rather_than_breaking(): void
    {
        $this->actingAs($this->admin());
        $this->book(['guest_name' => 'Tonight']);

        // A malformed query string must not break the screen somebody is
        // standing at.
        $this->get(route('admin.reservations.index', ['day' => 'not-a-date']))
            ->assertOk()
            ->assertSee('Tonight');
    }

    public function test_taking_a_booking_over_http(): void
    {
        $this->actingAs($this->admin());
        $table = $this->table();

        $this->postJson(route('admin.reservations.store'), [
            'guest_name' => 'Sharma',
            'guest_mobile' => '9876500000',
            'party_size' => 2,
            'reserved_for' => Carbon::today()->setTime(21, 0)->toDateTimeString(),
            'duration_minutes' => 90,
            'restaurant_table_id' => $table->id,
            'notes' => 'window seat',
        ])->assertOk();

        $this->assertSame('window seat', Reservation::query()->firstOrFail()->notes);
    }

    public function test_a_double_booking_over_http_is_refused_with_the_other_name(): void
    {
        $this->actingAs($this->admin());
        $table = $this->table();
        $this->book(['restaurant_table_id' => $table->id]);

        $response = $this->postJson(route('admin.reservations.store'), [
            'guest_name' => 'Rao',
            'party_size' => 2,
            'reserved_for' => Carbon::today()->setTime(20, 30)->toDateTimeString(),
            'duration_minutes' => 90,
            'restaurant_table_id' => $table->id,
        ]);

        $response->assertStatus(422);
        // Naming who has it is the difference between a refusal somebody can
        // act on and one they have to go and investigate.
        $this->assertStringContainsString('Mehta', $response->json('message'));
    }

    public function test_seating_over_http_opens_the_sitting(): void
    {
        $this->actingAs($this->admin());
        $table = $this->table();
        $booking = $this->book(['restaurant_table_id' => $table->id]);

        $this->putJson(route('admin.reservations.seat', $booking))->assertOk();

        $this->assertSame(Reservation::SEATED, $booking->fresh()->status);
        $this->assertSame(1, TableSession::allShops()->count());
    }

    public function test_a_seated_booking_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin());
        $table = $this->table();
        $booking = $this->book(['restaurant_table_id' => $table->id]);
        $this->service()->seat($booking);

        $this->deleteJson(route('admin.reservations.destroy', $booking))->assertStatus(422);

        $this->assertNotNull($booking->fresh());
    }

    public function test_the_screen_is_closed_without_the_right(): void
    {
        $staff = User::factory()->create(['is_admin' => true]);
        $this->actingAs($staff);

        $this->get(route('admin.reservations.index'))->assertForbidden();
    }
}
