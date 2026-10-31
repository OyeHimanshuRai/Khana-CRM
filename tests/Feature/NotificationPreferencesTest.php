<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Shop;
use App\Models\User;
use App\Services\AlertService;
use App\Support\NotificationPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Profile > Notifications: each person decides what makes a noise, what
 * shows on screen and what reaches their phone - per kind of event.
 */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
    }

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    private function staff(array $permissions): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Staff '.uniqid(),
            'email' => 'staff'.uniqid().'@example.test',
            'password' => 'staff-password-1',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill(['current_shop_id' => $shop->id, 'all_shops_view' => false])->save();
        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    public function test_everything_is_on_until_somebody_turns_it_off(): void
    {
        $user = $this->staff([]);
        $prefs = NotificationPreferences::for($user);

        $this->assertTrue($prefs['sound']);
        $this->assertTrue($prefs['voice']);
        $this->assertSame('en-IN', $prefs['voice_lang']);

        foreach (NotificationPreferences::types() as $type => $meta) {
            foreach (NotificationPreferences::CHANNELS as $channel) {
                $this->assertTrue(NotificationPreferences::wants($user, $type, $channel), "{$type}.{$channel}");
            }
        }
    }

    public function test_a_kind_added_later_is_on_for_people_who_saved_before_it_existed(): void
    {
        $user = $this->staff([]);
        $user->forceFill(['notification_preferences' => [
            'sound' => true,
            'types' => [Alert::LOW_STOCK => ['alert' => false, 'sound' => false, 'push' => false]],
        ]])->save();

        $this->assertFalse(NotificationPreferences::wants($user->fresh(), Alert::LOW_STOCK, 'sound'));
        $this->assertTrue(NotificationPreferences::wants($user->fresh(), Alert::ORDER_PLACED, 'sound'));
    }

    public function test_the_master_sound_switch_silences_every_kind(): void
    {
        $user = $this->staff([]);
        $user->forceFill(['notification_preferences' => ['sound' => false]])->save();

        $this->assertFalse(NotificationPreferences::wants($user->fresh(), Alert::ORDER_PLACED, 'sound'));
        // ...but not the toast or the phone: those have their own switches.
        $this->assertTrue(NotificationPreferences::wants($user->fresh(), Alert::ORDER_PLACED, 'alert'));
        $this->assertTrue(NotificationPreferences::wants($user->fresh(), Alert::ORDER_PLACED, 'push'));
    }

    public function test_the_screen_only_offers_what_this_person_can_receive(): void
    {
        $cashier = $this->staff(['pos.terminal.view', 'kitchen.tickets.view']);

        $this->actingAs($cashier)->get(route('admin.profile.notifications'))
            ->assertOk()
            ->assertSee('Kitchen ticket')
            ->assertSee('Low stock')
            ->assertDontSee('New sign-up')
            ->assertDontSee('Plan payment')
            ->assertSee('data-notify-test', false);
    }

    public function test_saving_stores_an_unticked_box_as_off(): void
    {
        $manager = $this->staff(['sales.orders.view']);

        $types = [];
        foreach (array_keys(NotificationPreferences::types()) as $type) {
            $types[$type] = ['alert' => '1', 'sound' => '1', 'push' => '1'];
        }
        // Orders: on screen, but silent and not on the phone.
        $types[Alert::ORDER_PLACED] = ['alert' => '1'];

        $this->actingAs($manager)->putJson(route('admin.profile.notifications.update'), [
            'sound' => '1', 'voice' => '0', 'voice_lang' => 'hi-IN', 'volume' => 40,
            'types' => $types,
        ])->assertOk()->assertJsonPath('message', 'Notification settings saved.');

        $prefs = NotificationPreferences::for($manager->fresh());

        $this->assertFalse($prefs['voice']);
        $this->assertSame('hi-IN', $prefs['voice_lang']);
        $this->assertSame(40, $prefs['volume']);
        $this->assertTrue($prefs['types'][Alert::ORDER_PLACED]['alert']);
        $this->assertFalse($prefs['types'][Alert::ORDER_PLACED]['sound']);
        $this->assertFalse($prefs['types'][Alert::ORDER_PLACED]['push']);
    }

    public function test_a_kind_the_screen_did_not_offer_keeps_its_setting(): void
    {
        $cashier = $this->staff(['pos.terminal.view']);
        $cashier->forceFill(['notification_preferences' => [
            'types' => [Alert::SIGNUP => ['alert' => true, 'sound' => true, 'push' => true]],
        ]])->save();

        // The cashier's form carries no box for sign-ups at all.
        $this->actingAs($cashier)->putJson(route('admin.profile.notifications.update'), [
            'sound' => '1', 'voice' => '1', 'voice_lang' => 'en-IN', 'volume' => 80, 'types' => [],
        ])->assertOk();

        $this->assertTrue(NotificationPreferences::for($cashier->fresh())['types'][Alert::SIGNUP]['sound']);
    }

    public function test_the_feed_says_what_to_announce_and_whether_to_show_it(): void
    {
        $manager = $this->staff(['sales.orders.view']);
        $manager->forceFill(['notification_preferences' => [
            'types' => [Alert::ORDER_PLACED => ['alert' => false, 'sound' => true, 'push' => true]],
        ]])->save();

        app(AlertService::class)->raise(
            $this->shop(), Alert::ORDER_PLACED, 'New order GF-04/1 · Table 4', 'order:x',
            can: 'sales.orders.view',
        );

        $item = collect($this->actingAs($manager->fresh())->getJson(route('admin.alerts.feed'))->json('data.items'))
            ->firstWhere('type', Alert::ORDER_PLACED);

        $this->assertSame(['en' => 'New order, Table 4', 'hi' => 'नया ऑर्डर, टेबल 4'], $item['spoken']);
        $this->assertFalse($item['toast'], 'listed, but not toasted');
    }

    public function test_every_admin_page_carries_the_settings_for_the_sound_engine(): void
    {
        $manager = $this->staff(['sales.orders.view']);
        $manager->forceFill(['notification_preferences' => ['voice_lang' => 'hi-IN']])->save();

        $this->actingAs($manager->fresh())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('id="notify-prefs"', false)
            ->assertSee('"voice_lang":"hi-IN"', false)
            ->assertSee('notify-sound.js', false)
            ->assertSee(route('admin.profile.notifications'), false);
    }

    public function test_each_kind_has_something_short_to_say(): void
    {
        foreach (array_keys(Alert::TYPES) as $type) {
            $spoken = (new Alert(['type' => $type, 'title' => 'Something']))->spoken();

            $this->assertNotSame('', $spoken['en'], $type);
            $this->assertNotSame('', $spoken['hi'], $type);
            $this->assertLessThan(60, mb_strlen($spoken['en']), $type.' is too long to say out loud');
        }
    }
}
