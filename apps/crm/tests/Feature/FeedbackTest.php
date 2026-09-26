<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\Floor;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Shop;
use App\Models\TableSession;
use App\Models\User;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What the guest thought (§15).
 *
 * The thing worth protecting here is that it stays easy to give. Every field
 * required is a person who leaves without answering, and the ones who give up
 * first are exactly the ones whose evening went badly - so a rating alone,
 * from somebody who never typed a name, has to be a complete submission.
 *
 * The second is that nothing in the admin can edit what a guest wrote. A
 * complaint that can be quietly softened is not feedback.
 */
class FeedbackTest extends TestCase
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

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function staff(array $permissions = []): User
    {
        $user = User::query()->firstWhere('email', 'manager@example.test');

        if ($user === null) {
            $user = User::create([
                'tenant_id' => $this->shop()->tenant_id,
                'name' => 'Manager',
                'email' => 'manager@example.test',
                'password' => 'manager-password-1',
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

    private function table(): RestaurantTable
    {
        $floor = Floor::create([
            'shop_id' => $this->shop()->id,
            'name' => 'Ground Floor',
            'code' => 'GF',
            'is_active' => true,
        ]);

        $table = RestaurantTable::create([
            'shop_id' => $floor->shop_id,
            'floor_id' => $floor->id,
            'name' => '4',
            'code' => 'GF-04',
            'capacity' => 4,
            'status' => RestaurantTable::AVAILABLE,
            'is_active' => true,
        ]);

        app(TableQrService::class)->issue($table);

        return $table->fresh(['activeQr']);
    }

    /** Scan the sticker and order something, the way a phone does. */
    private function seat(): TableSession
    {
        $table = $this->table();

        $this->get('/t/'.$table->activeQr->token)->assertRedirect(route('table.show'));

        $dish = Product::create([
            'name' => 'Dal Makhani',
            'slug' => Product::uniqueSlug('Dal Makhani'),
            'sku' => Product::generateSku('Dal Makhani'),
            'selling_price' => 280,
            'is_active' => true,
        ]);

        $this->post('/t/cart', ['product_id' => $dish->id])->assertRedirect();
        $this->post('/t/order')->assertRedirect(route('table.orders'));

        return TableSession::allShops()->live()->firstOrFail();
    }

    /* ------------------------------------------------------- the guest */

    public function test_a_rating_on_its_own_is_a_complete_answer(): void
    {
        $session = $this->seat();

        // No name, no email, no comment. The most honest feedback a
        // restaurant ever gets is one tap on the way out of the door.
        $this->post('/t/feedback', ['rating' => 2])
            ->assertRedirect(route('table.feedback'));

        $feedback = Feedback::allShops()->firstOrFail();

        $this->assertSame(2, $feedback->rating);
        $this->assertSame($session->id, $feedback->table_session_id);
        // Linked to the table and the order without the guest being asked.
        $this->assertNotNull($feedback->order_id);
    }

    public function test_the_split_ratings_are_optional_and_kept_when_given(): void
    {
        $this->seat();

        $this->post('/t/feedback', [
            'rating' => 4,
            'food_rating' => 5,
            'service_rating' => 3,
            'comment' => 'Food great, slow at the end',
        ])->assertRedirect();

        $feedback = Feedback::allShops()->firstOrFail();

        // A kitchen and a floor fail separately, and one number hides which.
        $this->assertSame(5, $feedback->food_rating);
        $this->assertSame(3, $feedback->service_rating);
    }

    public function test_a_phone_left_on_a_table_cannot_leave_forty_ratings(): void
    {
        $this->seat();

        $this->post('/t/feedback', ['rating' => 1]);
        $this->post('/t/feedback', ['rating' => 1]);
        $this->post('/t/feedback', ['rating' => 5]);

        $this->assertSame(1, Feedback::allShops()->count());
        // The last answer wins - somebody who changed their mind is not
        // counted twice.
        $this->assertSame(5, Feedback::allShops()->firstOrFail()->rating);
    }

    public function test_feedback_still_works_after_the_bill(): void
    {
        $session = $this->seat();

        // Most people rate a meal on the pavement outside, not at the table.
        app(\App\Services\TableSessionService::class)->close($session, 'paid');

        $this->get('/t/feedback')->assertOk();
        $this->post('/t/feedback', ['rating' => 5])->assertRedirect();

        $this->assertSame(1, Feedback::allShops()->count());
    }

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        $this->seat();

        $this->post('/t/feedback', ['rating' => 9])->assertSessionHasErrors('rating');
        $this->assertSame(0, Feedback::allShops()->count());
    }

    public function test_the_screen_needs_a_sitting(): void
    {
        $this->get('/t/feedback')->assertRedirect(route('table.expired'));
    }

    public function test_the_order_screen_offers_it(): void
    {
        $this->seat();

        $this->get('/t/orders')
            ->assertOk()
            ->assertSee('How was it?');
    }

    /* -------------------------------------------------------- the admin */

    public function test_the_list_opens_on_what_needs_answering(): void
    {
        $this->seat();
        $this->post('/t/feedback', ['rating' => 2, 'comment' => 'Cold soup']);

        $this->actingAs($this->staff(['crm.feedback.view']));
        CurrentShop::forget();

        $this->get(route('admin.feedback.index', ['attention' => 1]))
            ->assertOk()
            ->assertSee('Cold soup');
    }

    public function test_a_happy_rating_is_not_in_the_attention_list(): void
    {
        $this->seat();
        $this->post('/t/feedback', ['rating' => 5, 'comment' => 'Lovely evening']);

        $this->actingAs($this->staff(['crm.feedback.view']));
        CurrentShop::forget();

        // A feedback screen that opens on "all, newest first" is a screen of
        // four-stars, which is pleasant and useless.
        $this->get(route('admin.feedback.index', ['attention' => 1]))
            ->assertOk()
            ->assertDontSee('Lovely evening');
    }

    public function test_replying_records_who_and_when(): void
    {
        $this->seat();
        $this->post('/t/feedback', ['rating' => 1, 'comment' => 'Terrible']);

        $manager = $this->staff(['crm.feedback.view', 'crm.feedback.edit']);
        $this->actingAs($manager);
        CurrentShop::forget();

        $feedback = Feedback::allShops()->firstOrFail();

        $this->putJson(route('admin.feedback.respond', $feedback), [
            'response' => 'Rang them, refunded the starter.',
        ])->assertOk();

        $feedback->refresh();

        $this->assertTrue($feedback->isAnswered());
        $this->assertSame($manager->id, $feedback->responded_by);
        // What the guest said is untouched.
        $this->assertSame('Terrible', $feedback->comment);
    }

    public function test_answering_takes_it_off_the_attention_list(): void
    {
        $this->seat();
        $this->post('/t/feedback', ['rating' => 2, 'comment' => 'Cold soup']);

        $this->actingAs($this->staff(['crm.feedback.view', 'crm.feedback.edit']));
        CurrentShop::forget();

        $feedback = Feedback::allShops()->firstOrFail();

        $this->putJson(route('admin.feedback.respond', $feedback), ['response' => 'Sorted']);

        $this->get(route('admin.feedback.index', ['attention' => 1]))
            ->assertOk()
            ->assertDontSee('Cold soup');
    }

    public function test_replying_needs_its_own_right(): void
    {
        $this->seat();
        $this->post('/t/feedback', ['rating' => 2]);

        $this->actingAs($this->staff(['crm.feedback.view']));
        CurrentShop::forget();

        $this->putJson(route('admin.feedback.respond', Feedback::allShops()->firstOrFail()), [
            'response' => 'nope',
        ])->assertForbidden();
    }

    public function test_the_average_is_absent_rather_than_zero_before_anybody_rates(): void
    {
        $this->actingAs($this->staff(['crm.feedback.view']));
        CurrentShop::forget();

        // "0.0 out of 5" on a restaurant's first week is a lie that reads as
        // a disaster.
        $this->get(route('admin.feedback.index'))
            ->assertOk()
            ->assertSee('No ratings yet');
    }

    public function test_the_screen_is_closed_without_the_right(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get(route('admin.feedback.index'))->assertForbidden();
    }
}
