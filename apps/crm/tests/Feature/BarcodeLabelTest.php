<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Barcode/price sticker printing - the "Barcode Labels" screen the SRS asks
 * for in section 9 (barcode label/sticker printing with configurable label
 * size and product fields). Renders as SVG via picqer/php-barcode-generator
 * rather than through PHP's GD extension, which is not installed in every
 * environment this runs in - see BarcodeLabelController's docblock.
 */
class BarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::first();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    private function staff(array $permissions): User
    {
        static $counter = 0;
        $counter++;

        $user = User::create([
            'name' => 'Label Staff '.$counter,
            'email' => "labelstaff{$counter}@example.test",
            'password' => 'staff-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo($permissions);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $user;
    }

    private function product(array $attributes = []): Product
    {
        static $counter = 0;
        $counter++;

        return Product::create([
            'name' => 'Label Product '.$counter,
            'slug' => Product::uniqueSlug('Label Product '.$counter),
            'sku' => Product::generateSku('LBL'.$counter),
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            'tax_inclusive' => true,
            'is_active' => true,
            ...$attributes,
        ]);
    }

    public function test_a_permitted_staff_member_can_open_the_label_picker(): void
    {
        $staff = $this->staff(['pos.labels.view']);

        $this->actingAs($staff)->get(route('admin.labels.index'))->assertOk();
    }

    public function test_a_staff_member_without_permission_is_forbidden(): void
    {
        $staff = $this->staff([]);

        $this->actingAs($staff)->get(route('admin.labels.index'))->assertForbidden();
    }

    public function test_printing_generates_one_label_per_requested_copy_with_a_scannable_code(): void
    {
        $product = $this->product(['barcode' => '8901234567890']);
        $staff = $this->staff(['pos.labels.print']);

        $response = $this->actingAs($staff)->post(route('admin.labels.print'), [
            'size' => 'medium',
            'show_name' => 1,
            'show_price' => 1,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3],
            ],
        ]);

        $response->assertOk();
        $response->assertSeeInOrder([$product->name, $product->name, $product->name]);
        $response->assertSeeText('8901234567890');

        // An actual barcode symbol, not a placeholder image.
        $response->assertSee('<svg', false);
    }

    public function test_a_product_with_no_barcode_falls_back_to_its_sku(): void
    {
        $product = $this->product(['barcode' => null]);
        $staff = $this->staff(['pos.labels.print']);

        $response = $this->actingAs($staff)->post(route('admin.labels.print'), [
            'size' => 'small',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertOk();
        $response->assertSeeText($product->sku);
    }

    public function test_at_least_one_product_is_required(): void
    {
        $staff = $this->staff(['pos.labels.print']);

        $this->actingAs($staff)->post(route('admin.labels.print'), [
            'size' => 'small',
            'items' => [],
        ])->assertSessionHasErrors('items');
    }
}
