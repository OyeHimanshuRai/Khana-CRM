<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\ScannerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Settings > Company Settings > Barcode Scanner, end to end.
 *
 * The panel carries three overlapping switches - a master, one per input
 * method, and a Scanner Mode over the top - and the rule binding them is
 * narrowing only: a method is live when its own switch is on AND the mode
 * admits it. Every combination is asserted below because the failure mode is
 * silent and expensive: a counter is shown a Scan Barcode button that opens a
 * camera the endpoint will refuse, or a wireless scanner that types into a
 * field nothing is listening to.
 *
 * The render assertions matter for a second reason. Both input methods are
 * required to end in the same cart, the same invoice and the same stock
 * movement - there is deliberately no second billing page - so what the POS
 * screen ships is the only place that can be checked.
 */
class ScannerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private ?User $cashier = null;

    protected function setUp(): void
    {
        parent::setUp();

        // APP_URL carries a sub-path, which would prefix every request here
        // and miss the routes entirely.
        URL::forceRootUrl('http://localhost');

        $this->seed();
        CurrentShop::forget();

        $this->shop = Shop::firstOrFail();
    }

    protected function tearDown(): void
    {
        CurrentShop::forget();

        parent::tearDown();
    }

    /* ------------------------------------------------------------ fixtures */

    private function admin(): User
    {
        return User::where('is_admin', true)->firstOrFail();
    }

    /** Made once per test: several cases load the terminal more than once. */
    private function cashier(): User
    {
        if ($this->cashier !== null) {
            return $this->cashier;
        }

        $user = User::create([
            'name' => 'Counter Staff',
            'email' => 'scan-cashier@example.test',
            'password' => 'cashier-password-1',
            'is_admin' => true,
        ]);

        $user->givePermissionTo(['pos.terminal.view', 'pos.terminal.create']);
        $user->shops()->attach($this->shop->id, ['is_default' => true]);
        $user->forceFill(['current_shop_id' => $this->shop->id])->save();

        return $this->cashier = $user;
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function configure(array $overrides = []): void
    {
        Setting::put([
            'scanner_enabled' => '1',
            'scanner_hardware_input' => '1',
            'scanner_camera_enabled' => '1',
            'scanner_mode' => ScannerSettings::MODE_BOTH,
            'scanner_formats' => 'all',
            ...$overrides,
        ]);

        ScannerSettings::flush();
    }

    private function terminal(): string
    {
        return $this->actingAs($this->cashier())
            ->get('/admin/pos')
            ->assertOk()
            ->getContent();
    }

    /* ------------------------------------------------- mode x per-method */

    /**
     * @return list<array{string, string, string, string, bool, bool}>
     */
    public static function modeMatrix(): array
    {
        return [
            'both on, mode both' => ['1', '1', '1', 'both', true, true],
            'both on, mode wireless' => ['1', '1', '1', 'wireless', true, false],
            'both on, mode camera' => ['1', '1', '1', 'camera', false, true],

            // Mode narrows; it must never switch a method back on.
            'wireless off, mode both' => ['1', '0', '1', 'both', false, true],
            'camera off, mode both' => ['1', '1', '0', 'both', true, false],
            'wireless off, mode wireless' => ['1', '0', '1', 'wireless', false, false],
            'camera off, mode camera' => ['1', '1', '0', 'camera', false, false],

            'master off' => ['0', '1', '1', 'both', false, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modeMatrix')]
    public function test_scanner_mode_narrows_but_never_widens(
        string $enabled,
        string $wireless,
        string $camera,
        string $mode,
        bool $wirelessLive,
        bool $cameraLive,
    ): void {
        $this->configure([
            'scanner_enabled' => $enabled,
            'scanner_hardware_input' => $wireless,
            'scanner_camera_enabled' => $camera,
            'scanner_mode' => $mode,
        ]);

        $this->assertSame($wirelessLive, ScannerSettings::wirelessEnabled());
        $this->assertSame($cameraLive, ScannerSettings::cameraEnabled());
    }

    public function test_an_unknown_mode_falls_back_to_both(): void
    {
        $this->configure(['scanner_mode' => 'nonsense']);

        $this->assertSame(ScannerSettings::MODE_BOTH, ScannerSettings::mode());
        $this->assertTrue(ScannerSettings::wirelessEnabled());
        $this->assertTrue(ScannerSettings::cameraEnabled());
    }

    /* ------------------------------------------------------ scanner type */

    public function test_scanner_type_picks_the_symbologies(): void
    {
        $this->configure(['scanner_formats' => '1d']);
        $this->assertContains('EAN_13', ScannerSettings::formats());
        $this->assertNotContains('QR_CODE', ScannerSettings::formats());

        $this->configure(['scanner_formats' => 'qr']);
        $this->assertSame(['QR_CODE'], ScannerSettings::formats());

        $this->configure(['scanner_formats' => '2d']);
        $this->assertContains('DATA_MATRIX', ScannerSettings::formats());
        $this->assertNotContains('EAN_13', ScannerSettings::formats());

        $this->configure(['scanner_formats' => 'all']);
        $this->assertContains('EAN_13', ScannerSettings::formats());
        $this->assertContains('QR_CODE', ScannerSettings::formats());
    }

    /**
     * An empty list would tell the decoder to look for no symbologies at all,
     * which reads nothing - strictly worse than ignoring the bad value.
     */
    public function test_an_unknown_scanner_type_still_decodes_everything(): void
    {
        $this->configure(['scanner_formats' => 'nonsense']);

        $this->assertContains('EAN_13', ScannerSettings::formats());
        $this->assertContains('QR_CODE', ScannerSettings::formats());
    }

    public function test_the_format_list_serialises_as_a_json_array(): void
    {
        $this->configure(['scanner_formats' => 'all']);

        $this->assertStringContainsString(
            '"formats":["EAN_13"',
            json_encode(ScannerSettings::forJs()),
        );
    }

    /* -------------------------------------------------------- the screen */

    public function test_both_methods_ship_on_one_page(): void
    {
        $this->configure();

        $html = $this->terminal();

        // One cart, reached two ways: the scan field a wireless scanner types
        // into, and the camera button - both inside the same [data-line-items]
        // container, posting through the same form.
        $this->assertStringContainsString('id="pos-scan"', $html);
        $this->assertStringContainsString('scanner-camera-btn', $html);
        $this->assertStringContainsString('scanner-camera-cta', $html);
        $this->assertStringContainsString('data-line-items', $html);
        $this->assertSame(1, substr_count($html, 'data-line-body'));
    }

    public function test_camera_only_mode_drops_the_camera_markup_and_its_library(): void
    {
        $this->configure(['scanner_mode' => 'wireless']);

        $html = $this->terminal();

        $this->assertStringNotContainsString('data-scanner-camera', $html);
        $this->assertStringNotContainsString('html5-qrcode', $html);
        // The scan field is the point of this mode, so it stays.
        $this->assertStringContainsString('id="pos-scan"', $html);
    }

    public function test_wireless_only_mode_drops_the_keyboard_scan_behaviour(): void
    {
        $this->configure(['scanner_mode' => 'camera']);

        $html = $this->terminal();

        $this->assertStringContainsString('data-scanner-camera', $html);
        // Nothing is typing a burst ending in Enter any more, so Enter goes
        // back to meaning only what the operator meant by it.
        $this->assertStringContainsString('data-scanner-auto-detect="0"', $html);
    }

    public function test_the_master_switch_removes_both_methods_but_keeps_search(): void
    {
        $this->configure(['scanner_enabled' => '0']);

        $html = $this->terminal();

        $this->assertStringNotContainsString('data-scanner-camera', $html);
        $this->assertStringContainsString('data-scanner-auto-detect="0"', $html);
        // Searching by name is not scanning and must survive.
        $this->assertStringContainsString('id="pos-scan"', $html);
    }

    public function test_auto_enter_off_reaches_the_page(): void
    {
        $this->configure(['scanner_auto_enter' => '0']);
        $this->assertStringContainsString('data-scanner-auto-enter="0"', $this->terminal());

        $this->configure(['scanner_auto_enter' => '1']);
        $this->assertStringNotContainsString('data-scanner-auto-enter', $this->terminal());
    }

    public function test_the_chosen_symbologies_reach_the_page(): void
    {
        $this->configure(['scanner_formats' => 'qr']);

        $html = $this->terminal();

        $this->assertStringContainsString('QR_CODE', $html);
        $this->assertStringNotContainsString('EAN_13', $html);
    }

    /* --------------------------------------------------------- the panel */

    public function test_the_settings_panel_offers_every_control(): void
    {
        $html = $this->actingAs($this->admin())
            ->get('/admin/settings/company')
            ->assertOk()
            ->assertSee('Barcode Scanner')
            ->getContent();

        foreach ([
            'scanner_enabled', 'scanner_hardware_input', 'scanner_camera_enabled',
            'scanner_mode', 'scanner_formats', 'scanner_auto_add', 'scanner_auto_focus',
            'scanner_auto_enter', 'scanner_auto_detect', 'scanner_duplicate_qty_increment',
            'scanner_success_sound', 'scanner_error_sound', 'scanner_vibrate',
            'scanner_search_by',
        ] as $field) {
            $this->assertStringContainsString('set-'.$field, $html, "missing field: {$field}");
        }
    }

    public function test_the_panel_saves(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/admin/settings/company/scanner', [
                'scanner_enabled' => '1',
                'scanner_hardware_input' => '0',
                'scanner_camera_enabled' => '1',
                'scanner_mode' => 'camera',
                'scanner_formats' => '2d',
                'scanner_auto_add' => '1',
                'scanner_auto_focus' => '1',
                'scanner_auto_enter' => '0',
                'scanner_auto_detect' => '1',
                'scanner_duplicate_qty_increment' => '1',
                'scanner_success_sound' => '0',
                'scanner_error_sound' => '0',
                'scanner_vibrate' => '1',
                'scanner_search_by' => 'barcode',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        ScannerSettings::flush();

        $this->assertSame('camera', Setting::get('scanner_mode'));
        $this->assertSame('2d', Setting::get('scanner_formats'));
        $this->assertSame('0', Setting::get('scanner_auto_enter'));
        $this->assertSame('1', Setting::get('scanner_vibrate'));

        $this->assertTrue(ScannerSettings::cameraEnabled());
        $this->assertFalse(ScannerSettings::wirelessEnabled());
        $this->assertFalse(ScannerSettings::autoEnter());
    }

    public function test_the_new_selects_reject_values_they_do_not_offer(): void
    {
        $this->actingAs($this->admin())
            ->putJson('/admin/settings/company/scanner', [
                'scanner_mode' => 'sideways',
                'scanner_formats' => '5d',
                'scanner_vibrate' => 'maybe',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['scanner_mode', 'scanner_formats', 'scanner_vibrate']]);
    }

    /* ------------------------------------------------------- the endpoint */

    public function test_one_endpoint_serves_both_methods(): void
    {
        $this->configure();

        $product = $this->scannable('8901234567890');

        // Nothing in the request says which method produced the code, and
        // nothing needs to: the wireless field and the camera both send a
        // bare string here and both get back the shape LineItems.add() takes.
        $this->actingAs($this->cashier())
            ->postJson('/admin/api/scanner/product', ['code' => '8901234567890'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.barcode', '8901234567890');
    }

    public function test_the_endpoint_refuses_every_scan_when_the_master_switch_is_off(): void
    {
        $this->configure(['scanner_enabled' => '0']);
        $this->scannable('8901234567890');

        $this->actingAs($this->cashier())
            ->postJson('/admin/api/scanner/product', ['code' => '8901234567890'])
            ->assertStatus(423)
            ->assertJsonPath('success', false);
    }

    /**
     * Scanner Mode is not consulted here on purpose: the endpoint cannot tell
     * which method sent the string, so refusing on mode would be a guess.
     */
    public function test_scanner_mode_does_not_gate_the_endpoint(): void
    {
        $this->configure(['scanner_mode' => 'wireless']);
        $this->scannable('8901234567890');

        $this->actingAs($this->cashier())
            ->postJson('/admin/api/scanner/product', ['code' => '8901234567890'])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function scannable(string $barcode): Product
    {
        return Product::create([
            'name' => 'Scannable',
            'slug' => Product::uniqueSlug('Scannable'),
            'sku' => Product::generateSku('SCAN'),
            'barcode' => $barcode,
            'unit_id' => Unit::where('code', 'PCS')->firstOrFail()->id,
            'tax_rate_id' => TaxRate::where('name', 'GST 18%')->firstOrFail()->id,
            'purchase_price' => 100,
            'mrp' => 200,
            'selling_price' => 180,
            'tax_inclusive' => true,
        ]);
    }
}
