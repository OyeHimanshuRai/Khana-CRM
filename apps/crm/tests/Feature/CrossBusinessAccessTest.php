<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A Tenant Owner manages their own branches. These are the ways the branch
 * screens reached past that: a URL naming another business's shop, a form
 * listing every business's staff, and a member list that would take anybody's
 * id.
 */
class CrossBusinessAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Shop $shop;

    private Tenant $rival;

    private Shop $rivalShop;

    private User $rivalStaff;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();

        $this->tenant = Tenant::query()->orderBy('id')->firstOrFail();
        $this->shop = Shop::query()->where('tenant_id', $this->tenant->id)->orderBy('id')->firstOrFail();

        $this->rival = Tenant::query()->create([
            'name' => 'Rival Kitchens',
            'code' => 'RIVAL',
            'slug' => Tenant::uniqueSlug('Rival Kitchens'),
            'is_active' => true,
        ]);

        $this->rivalShop = Shop::query()->withoutGlobalScopes()->create([
            'tenant_id' => $this->rival->id,
            'name' => 'Rival Kitchens Main',
            'code' => 'RVL1',
            'slug' => 'rival-kitchens-main',
            'is_active' => true,
        ]);

        $this->rivalShop->forceFill(['logo_path' => 'logos/rival.png'])->save();

        $this->rivalStaff = User::create([
            'tenant_id' => $this->rival->id,
            'name' => 'Rival Manager',
            'email' => 'manager@rival.example',
            'password' => 'a-strong-password-1',
            'is_admin' => true,
        ]);

        CurrentShop::forget();
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();
        CurrentTenant::forget();

        parent::tearDown();
    }

    private function owner(): User
    {
        $owner = User::query()->where('email', 'owner@example.test')->first();

        if ($owner === null) {
            $owner = User::create([
                'tenant_id' => $this->tenant->id,
                'name' => 'Owner',
                'email' => 'owner@example.test',
                'password' => 'a-strong-password-1',
                'is_admin' => true,
            ]);

            $owner->assignRole('Tenant Owner');
            $owner->shops()->syncWithoutDetaching([$this->shop->id => ['is_default' => true]]);
            $owner->forceFill(['current_shop_id' => $this->shop->id, 'all_shops_view' => false])->save();
        }

        return $owner->fresh();
    }

    public function test_an_owner_cannot_remove_another_business_s_shop_logo(): void
    {
        $this->actingAs($this->owner())
            ->deleteJson('/admin/shops/'.$this->rivalShop->id.'/logo')
            ->assertForbidden();

        $this->assertSame('logos/rival.png', $this->rivalShop->fresh()->logo_path);
    }

    public function test_the_branch_form_lists_only_the_business_s_own_staff(): void
    {
        $owner = $this->owner();

        foreach (['/admin/shops/create', '/admin/shops/'.$this->shop->id.'/edit'] as $url) {
            $this->actingAs($owner)
                ->get($url)
                ->assertOk()
                ->assertSee($owner->email)
                ->assertDontSee('manager@rival.example');
        }
    }

    public function test_an_owner_cannot_enrol_another_business_s_staff(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->putJson('/admin/shops/'.$this->shop->id, [
                'name' => $this->shop->name,
                'code' => $this->shop->code,
                'users' => [$owner->id, $this->rivalStaff->id],
            ])
            ->assertOk();

        $members = $this->shop->users()->pluck('users.id')->all();

        $this->assertContains($owner->id, $members);
        $this->assertNotContains($this->rivalStaff->id, $members);
    }
}
