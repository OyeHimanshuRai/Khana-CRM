<?php

namespace Khana\Kit\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Khana\Kit\Tenancy\BelongsToOutlet;
use Khana\Kit\Tenancy\BelongsToTenant;
use Khana\Kit\Tenancy\Context;
use Khana\Kit\Tenancy\CurrentOutlet;
use Khana\Kit\Tenancy\CurrentTenant;
use Khana\Kit\Tenancy\Directory;
use Khana\Kit\Tenancy\OutletAccessDenied;
use Khana\Kit\Tests\Fixtures\Staff;
use Khana\Kit\Tests\TestCase;

/**
 * The tenancy walls: nobody reads or writes across the line by accident, and
 * the failure mode is always an empty list, never somebody else's data.
 *
 * Keys are integers here, as in the monolith's tables; the ULID-keyed copy of
 * Accounts is exercised in PlansAndIsolationTest.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    // Business 1 has outlets 11 and 12; business 2 has outlet 21.
    private const OUTLETS = [1 => [11, 12], 2 => [21]];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Directory::class, new class(self::OUTLETS) implements Directory
        {
            public function __construct(private array $outlets) {}

            public function tenantKeys(): array
            {
                return array_keys($this->outlets);
            }

            public function outletKeys(int|string|null $tenantKey): array
            {
                return $tenantKey === null
                    ? array_merge(...array_values($this->outlets))
                    : ($this->outlets[$tenantKey] ?? []);
            }
        });

        Schema::create('test_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('note')->nullable();
        });

        Schema::create('test_legacy_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->nullable();
        });

        Schema::create('test_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
        });

        foreach ([11, 11, 12, 21] as $outlet) {
            TestOrder::allOutlets()->insert(['outlet_id' => $outlet]);
        }

        TestCategory::allTenants()->insert([['tenant_id' => 1, 'name' => 'Starters'], ['tenant_id' => 2, 'name' => 'Mains']]);
    }

    private function actingAsStaff(Staff $staff): void
    {
        $this->actingAs($staff);
        $this->app->make(Context::class)->forget();
    }

    /** @return list<int> */
    private function visibleOutlets(): array
    {
        return TestOrder::query()->orderBy('id')->pluck('outlet_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_staff_see_only_the_outlet_they_are_in(): void
    {
        $this->actingAsStaff(Staff::at(1, [11, 12], chosen: 11));

        $this->assertSame([11, 11], $this->visibleOutlets());
        $this->assertSame(11, CurrentOutlet::id());
    }

    public function test_all_outlets_means_their_outlets_not_everyone_s(): void
    {
        $staff = Staff::at(1, [11, 12]);
        $staff->all = true;
        $this->actingAsStaff($staff);

        $this->assertNull(CurrentOutlet::id());
        $this->assertSame([11, 11, 12], $this->visibleOutlets());
    }

    public function test_an_outlet_of_another_business_cannot_be_reached_even_if_assigned(): void
    {
        // A stray pivot row pointing at business 2's outlet must not open it.
        $this->actingAsStaff(Staff::at(1, [11, 21], chosen: 21));

        $this->assertFalse(CurrentOutlet::canAccess(21));
        $this->assertSame(11, CurrentOutlet::id());
        $this->assertNotContains(21, $this->visibleOutlets());
    }

    public function test_staff_with_no_outlets_see_nothing_not_everything(): void
    {
        $this->actingAsStaff(Staff::at(1, []));

        $this->assertSame([], $this->visibleOutlets());
    }

    public function test_staff_with_no_business_on_a_shared_install_see_nothing(): void
    {
        $this->actingAsStaff(Staff::at(null, [11]));

        $this->assertSame([], $this->visibleOutlets());
        $this->assertSame([], TestCategory::query()->pluck('name')->all());
    }

    public function test_a_lost_outlet_falls_back_rather_than_leaking(): void
    {
        $staff = Staff::at(1, [12], chosen: 11);
        $staff->defaultOutlet = 12;
        $this->actingAsStaff($staff);

        $this->assertSame(12, CurrentOutlet::id());
        $this->assertSame([12], $this->visibleOutlets());
    }

    public function test_nobody_signed_in_is_the_system_and_must_name_its_outlet(): void
    {
        $this->assertSame([11, 11, 12, 21], $this->visibleOutlets());
        $this->assertSame(1, TestOrder::forOutlet(21)->count());
    }

    public function test_someone_signed_in_who_is_not_staff_sees_nothing(): void
    {
        // A storefront customer, an API client of another kind: unknown to
        // the tenancy rules, so the answer is nothing rather than a guess.
        $this->actingAs(new User());

        $this->assertSame([], $this->visibleOutlets());
        $this->assertSame([], TestCategory::query()->pluck('name')->all());
    }

    public function test_platform_staff_see_every_business_until_they_choose_one(): void
    {
        $admin = Staff::platformAdmin();
        $admin->all = true;
        $this->actingAsStaff($admin);

        $this->assertNull(CurrentTenant::id());
        $this->assertSame([11, 11, 12, 21], $this->visibleOutlets());

        $this->assertTrue(CurrentTenant::set(2));
        $this->assertSame([21], $this->visibleOutlets());
        $this->assertSame(['Mains'], TestCategory::query()->pluck('name')->all());
    }

    public function test_ordinary_staff_cannot_switch_business(): void
    {
        $this->actingAsStaff(Staff::at(1, [11]));

        $this->assertFalse(CurrentTenant::set(2));
        $this->assertSame(['Starters'], TestCategory::query()->pluck('name')->all());
    }

    public function test_a_new_row_is_stamped_with_the_outlet_in_context(): void
    {
        $this->actingAsStaff(Staff::at(1, [11, 12], chosen: 12));

        $order = TestOrder::query()->create(['note' => 'new']);

        $this->assertSame(12, (int) $order->outlet_id);
    }

    public function test_writing_into_an_outlet_out_of_reach_is_refused(): void
    {
        $this->actingAsStaff(Staff::at(1, [11], chosen: 11));

        $this->expectException(OutletAccessDenied::class);

        TestOrder::query()->create(['outlet_id' => 21, 'note' => 'smuggled']);
    }

    public function test_writing_into_a_business_out_of_reach_is_refused(): void
    {
        $this->actingAsStaff(Staff::at(1, [11]));

        $this->expectException(OutletAccessDenied::class);

        TestCategory::query()->create(['tenant_id' => 2, 'name' => 'Smuggled']);
    }

    public function test_switching_checks_access_and_is_remembered(): void
    {
        $staff = Staff::at(1, [11, 12], chosen: 11);
        $this->actingAsStaff($staff);

        $this->assertFalse(CurrentOutlet::set(21));
        $this->assertTrue(CurrentOutlet::set('12'));
        $this->assertSame(12, CurrentOutlet::id());
        $this->assertSame(12, $staff->chosenOutlet);

        $this->assertTrue(CurrentOutlet::set(null));
        $this->assertTrue($staff->all);
    }

    public function test_all_outlets_is_refused_with_only_one_to_consolidate(): void
    {
        $this->actingAsStaff(Staff::at(1, [11]));

        $this->assertFalse(CurrentOutlet::set(null));
    }

    public function test_a_legacy_column_name_is_scoped_the_same_way(): void
    {
        TestLegacyOrder::allOutlets()->insert([['shop_id' => 11], ['shop_id' => 21]]);
        $this->actingAsStaff(Staff::at(1, [11], chosen: 11));

        $this->assertSame([11], TestLegacyOrder::query()->pluck('shop_id')->map(fn ($id) => (int) $id)->all());
    }
}

class TestOrder extends Model
{
    use BelongsToOutlet;

    protected $table = 'test_orders';

    public $timestamps = false;

    protected $guarded = [];
}

class TestLegacyOrder extends Model
{
    use BelongsToOutlet;

    public const OUTLET_COLUMN = 'shop_id';

    protected $table = 'test_legacy_orders';

    public $timestamps = false;

    protected $guarded = [];
}

class TestCategory extends Model
{
    use BelongsToTenant;

    protected $table = 'test_categories';

    public $timestamps = false;

    protected $guarded = [];
}
