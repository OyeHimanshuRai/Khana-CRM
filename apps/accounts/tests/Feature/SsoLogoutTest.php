<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// GET /sso/logout: CRM and ERP send people here so Accounts forgets them too.
final class SsoLogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'khana.urls.crm' => 'https://khana.test',
            'khana.urls.erp' => 'https://khana.test/erp',
        ]);
    }

    private function person(): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Asha',
            'email' => 'asha@khana.test',
            'password' => 'password',
            'status' => User::ACTIVE,
        ])->save();

        return $user;
    }

    public function test_it_signs_out_and_goes_back_to_the_product(): void
    {
        $this->actingAs($this->person())
            ->get('/sso/logout?redirect='.urlencode('https://khana.test/admin/login'))
            ->assertRedirect('https://khana.test/admin/login');

        $this->assertGuest();
    }

    public function test_it_goes_back_to_erp_under_its_prefix(): void
    {
        $this->actingAs($this->person())
            ->get('/sso/logout?redirect='.urlencode('https://khana.test/erp/login'))
            ->assertRedirect('https://khana.test/erp/login');
    }

    public function test_it_never_sends_anyone_to_another_site(): void
    {
        $person = $this->person();

        foreach (['https://evil.test/admin/login', 'https://khana.test.evil.test/x', '//evil.test', 'javascript:alert(1)'] as $url) {
            $this->actingAs($person)
                ->get('/sso/logout?redirect='.urlencode($url))
                ->assertRedirect(route('login'));

            $this->assertGuest();
        }
    }

    public function test_signing_in_again_after_it_asks_for_the_password(): void
    {
        $this->actingAs($this->person())->get('/sso/logout');

        $this->get('/')->assertRedirect(route('login'));
    }
}
