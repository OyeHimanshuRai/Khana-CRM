<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\StorefrontSlide;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StorefrontManagementTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;

    private Shop $shopB;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');
        Storage::fake('public');
        $this->seed();

        $this->shopA = Shop::firstOrFail();
        $this->shopB = Shop::create([
            'name' => 'Second Store',
            'code' => 'SECOND',
            'slug' => 'second-store',
            'is_active' => true,
        ]);

        $this->selectShop($this->shopA);
    }

    public function test_admin_can_edit_storefront_branding_and_contact_details(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.storefront.edit'))
            ->assertOk()
            ->assertSee('Online Store')
            ->assertSee('Homepage slider')
            ->assertSee('Preview store');

        $this->actingAs($admin)
            ->put(route('admin.storefront.update'), [
                'storefront_tagline' => 'Made fresh every day',
                'phone' => '+91 98765 43210',
                'email' => 'hello@first-store.test',
                'address_line1' => '1 Market Road',
                'city' => 'Jaipur',
                'state' => 'Rajasthan',
                'pincode' => '302001',
                'country' => 'India',
                'storefront_logo' => UploadedFile::fake()->image('header.png', 500, 180),
                'storefront_footer_logo' => UploadedFile::fake()->image('footer.png', 500, 180),
            ])
            ->assertRedirect(route('admin.storefront.edit'));

        $savedShop = $this->shopA->fresh();
        $this->assertSame('Made fresh every day', $savedShop->storefront_tagline);
        $this->assertSame('+91 98765 43210', $savedShop->phone);
        $this->assertSame('Jaipur', $savedShop->city);
        $this->assertStringStartsWith('shopfront/'.$this->shopA->id.'/', $savedShop->storefront_logo_path);
        $this->assertStringStartsWith('shopfront/'.$this->shopA->id.'/', $savedShop->storefront_footer_logo_path);
        $this->assertTrue(Storage::disk('public')->exists($savedShop->storefront_logo_path));
        $this->assertTrue(Storage::disk('public')->exists($savedShop->storefront_footer_logo_path));
    }

    public function test_shop_admin_can_create_and_update_a_public_slide(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.storefront.slides.store'), [
                'title' => 'Fresh from our kitchen',
                'description' => 'Seasonal dishes made just for you.',
                'button_label' => 'Browse the menu',
                'button_url' => '/shop/'.$this->shopA->slug.'/catalog',
                'sort_order' => 0,
                'is_active' => 1,
                'desktop_image' => UploadedFile::fake()->image('desktop.jpg', 1600, 900),
                'mobile_image' => UploadedFile::fake()->image('mobile.jpg', 900, 1400),
            ])
            ->assertRedirect(route('admin.storefront.edit'));

        $slide = StorefrontSlide::query()->where('shop_id', $this->shopA->id)->firstOrFail();

        $this->assertTrue(Storage::disk('public')->exists($slide->desktop_image_path));
        $this->assertTrue(Storage::disk('public')->exists($slide->mobile_image_path));

        $this->actingAs($admin)
            ->put(route('admin.storefront.slides.update', $slide->id), [
                'title' => 'Updated shop headline',
                'description' => 'Now with a new description.',
                'button_label' => 'Order now',
                'button_url' => route('shop.catalog', $this->shopA),
                'sort_order' => 1,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.storefront.edit'));

        $secondSlide = $this->shopA->storefrontSlides()->make([
            'title' => 'Another shop highlight',
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $secondSlide->desktop_image_path = 'shopfront/'.$this->shopA->id.'/second.jpg';
        $secondSlide->save();

        $this->get(route('shop.home', $this->shopA))
            ->assertOk()
            ->assertSee('Updated shop headline')
            ->assertSee('Now with a new description.')
            ->assertSee('Order now')
            ->assertSee('data-shop-carousel', false)
            ->assertSee('data-shop-slide-next', false)
            ->assertSee('Another shop highlight');
    }

    public function test_shop_slides_and_logos_do_not_leak_between_shops(): void
    {
        $slide = $this->shopA->storefrontSlides()->make([
            'title' => 'Only Shop A',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $slide->desktop_image_path = 'shopfront/'.$this->shopA->id.'/a.jpg';
        $slide->save();

        $this->shopA->forceFill([
            'storefront_logo_path' => 'shopfront/'.$this->shopA->id.'/logo.png',
        ])->save();

        $this->selectShop($this->shopB);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.storefront.edit'))
            ->assertOk()
            ->assertDontSee('Only Shop A');

        $this->actingAs($admin)
            ->put(route('admin.storefront.slides.update', $slide->id), [
                'title' => 'Attempt to cross shops',
                'is_active' => 1,
            ])
            ->assertNotFound();

        $this->get(route('shop.home', $this->shopB))
            ->assertOk()
            ->assertDontSee('Only Shop A');
    }

    public function test_unsafe_slide_button_urls_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.storefront.slides.store'), [
                'title' => 'Unsafe link',
                'button_label' => 'Click here',
                'button_url' => 'javascript:alert(1)',
                'is_active' => 1,
                'desktop_image' => UploadedFile::fake()->image('desktop.jpg', 1600, 900),
            ])
            ->assertSessionHasErrors('button_url');

        $this->assertDatabaseMissing('storefront_slides', ['title' => 'Unsafe link']);
    }

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    private function selectShop(Shop $shop): void
    {
        $this->actingAs($this->admin());
        CurrentShop::forget();
        $this->assertTrue(CurrentShop::set($shop->id));
    }
}
