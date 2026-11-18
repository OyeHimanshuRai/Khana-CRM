<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** A dialog screen typed into the address bar gets the admin page around it. */
class ModalFragmentPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        URL::forceRootUrl('http://localhost');
        $this->seed();
    }

    private function admin(): User
    {
        return User::query()->where('email', 'admin@erp.test')->firstOrFail();
    }

    public function test_a_browser_visit_gets_the_full_admin_page(): void
    {
        $url = route('admin.customers.create');

        $html = $this->actingAs($this->admin())->get($url, ['Sec-Fetch-Dest' => 'document'])
            ->assertOk()->getContent();

        $this->assertStringContainsStringIgnoringCase('<html', $html);
        $this->assertStringContainsString('data-fragment-page', $html);
        $this->assertStringContainsString('name="name"', $html);
    }

    public function test_the_dialog_request_still_gets_the_bare_fragment(): void
    {
        $url = route('admin.customers.create');

        $html = $this->actingAs($this->admin())->get($url, ['X-Requested-With' => 'XMLHttpRequest', 'Sec-Fetch-Dest' => 'document'])
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('data-fragment-page', $html);

        $html = $this->actingAs($this->admin())->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-fragment-page', $html);
    }

    public function test_a_normal_page_is_not_wrapped_twice(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.customers.index'), ['Sec-Fetch-Dest' => 'document'])
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('data-fragment-page', $html);
    }
}
