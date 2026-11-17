<?php

namespace Tests\Feature;

use App\Mail\MailSettingsTestMail;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** Settings > General > Mail: "Send test email". */
class MailTestSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        $this->seed();
    }

    private function super(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    public function test_the_mail_tab_offers_the_test_box_and_it_sends(): void
    {
        Mail::fake();

        $this->actingAs($this->super())->get(route('admin.settings.company'))
            ->assertOk()
            ->assertSee('Send test email');

        $this->actingAs($this->super())
            ->postJson(route('admin.settings.company.test-mail'), ['email' => 'me@example.test'])
            ->assertOk()
            ->assertJsonFragment(['success' => true]);

        Mail::assertSent(MailSettingsTestMail::class, fn ($m) => $m->hasTo('me@example.test'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'settings.mail_test']);
    }

    public function test_a_failed_send_reports_the_servers_error(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused by smtp.example.test'));

        $this->actingAs($this->super())
            ->postJson(route('admin.settings.company.test-mail'), ['email' => 'me@example.test'])
            ->assertStatus(502)
            ->assertSee('Connection refused by smtp.example.test');
    }

    public function test_the_address_must_be_valid(): void
    {
        Mail::fake();

        $this->actingAs($this->super())
            ->postJson(route('admin.settings.company.test-mail'), ['email' => 'not-an-email'])
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_a_restaurant_cannot_use_it(): void
    {
        Mail::fake();
        $shop = Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $owner = User::create([
            'tenant_id' => $shop->tenant_id, 'name' => 'Owner', 'email' => 'o@example.test',
            'password' => 'Secret-1234', 'is_admin' => true, 'is_active' => true, 'current_shop_id' => $shop->id,
        ]);
        $owner->shops()->attach($shop->id, ['is_default' => true]);
        $owner->assignRole('Tenant Owner');

        $this->actingAs($owner->fresh())
            ->postJson(route('admin.settings.company.test-mail'), ['email' => 'me@example.test'])
            ->assertForbidden();

        Mail::assertNothingSent();
    }
}
