<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;

/**
 * Kitchen stations, and the routing that makes the KDS worth looking at (§9).
 *
 * A board with every dish on one screen proves nothing: the whole point of
 * routing is that the bar stops seeing biryani, and that only shows up when
 * there is more than one station and the sections are actually pointed at
 * them.
 *
 * The windows are deliberately different. A bar holding a drink order for six
 * minutes is late; a tandoor six minutes into a raan has barely started. With
 * one number for all of them the amber and the red on the board mean nothing,
 * and this is the seeder that makes that visible.
 *
 * Runs after DemoMenuSeeder, because it routes the sections that seeder wrote.
 */
class DemoKitchenSeeder extends Seeder
{
    use SeedsDemoData;

    /** name => [code, minutes before a ticket reads late, default?] */
    private const STATIONS = [
        'Main Kitchen' => ['MK', 18, true],
        'Tandoor' => ['TAN', 22, false],
        'Bar' => ['BAR', 6, false],
        'Bakery' => ['BKY', 12, false],
    ];

    /**
     * Which section goes where.
     *
     * Set on the parent wherever possible - "Main Course → Main Kitchen"
     * covers the four sub-sections under it without four rows, which is the
     * inheritance KitchenRouter exists to walk. Only the exceptions are named
     * individually.
     *
     * @var array<string, string>
     */
    private const ROUTING = [
        'Starters' => 'Main Kitchen',
        'Main Course' => 'Main Kitchen',
        'Indian Breads' => 'Tandoor',
        'Breakfast' => 'Main Kitchen',
        'Pizza' => 'Bakery',
        'Desserts' => 'Bakery',
        'Beverages' => 'Bar',
    ];

    /** Dishes that do not follow their section. The per-item override. */
    private const EXCEPTIONS = [
        'Tandoori Chicken' => 'Tandoor',
        'Paneer Tikka' => 'Tandoor',
    ];

    public function run(): void
    {
        $this->seedRandom(9);

        $stations = $this->stations();

        if ($stations->isEmpty()) {
            return;
        }

        $this->routeCategories($stations);
        $this->routeExceptions($stations);
    }

    /**
     * @return \Illuminate\Support\Collection<string, KitchenStation>
     */
    private function stations(): \Illuminate\Support\Collection
    {
        $shopId = CurrentShop::id();

        if ($shopId === null) {
            $this->command?->warn('  No shop in context; skipping kitchen stations.');

            return collect();
        }

        $order = 0;
        $made = collect();

        foreach (self::STATIONS as $name => [$code, $minutes, $isDefault]) {
            // firstOrCreate, so a half-finished run can be repeated without
            // colliding with the unique index on (shop_id, code).
            $station = KitchenStation::query()->firstOrCreate(
                ['shop_id' => $shopId, 'code' => $code],
                [
                    'name' => $name,
                    'prep_minutes' => $minutes,
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );

            if ($isDefault && ! $station->is_default) {
                $station->makeDefault();
            }

            $made->put($name, $station);
            $order++;
        }

        $this->say(sprintf('%d kitchen stations.', $made->count()));

        return $made;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, KitchenStation>  $stations
     */
    private function routeCategories(\Illuminate\Support\Collection $stations): void
    {
        $routed = 0;

        foreach (self::ROUTING as $category => $stationName) {
            $station = $stations->get($stationName);

            if ($station === null) {
                continue;
            }

            // Only where nobody has routed it already: a demo seeder must not
            // undo a decision somebody made on the screen it is seeding for.
            $routed += Category::query()
                ->where('name', $category)
                ->whereNull('kitchen_station_id')
                ->update(['kitchen_station_id' => $station->id]);
        }

        $this->say(sprintf('%d sections routed.', $routed));
    }

    /**
     * @param  \Illuminate\Support\Collection<string, KitchenStation>  $stations
     */
    private function routeExceptions(\Illuminate\Support\Collection $stations): void
    {
        $routed = 0;

        foreach (self::EXCEPTIONS as $dish => $stationName) {
            $station = $stations->get($stationName);

            if ($station === null) {
                continue;
            }

            $routed += Product::query()
                ->where('name', $dish)
                ->whereNull('kitchen_station_id')
                ->update(['kitchen_station_id' => $station->id]);
        }

        if ($routed > 0) {
            $this->say(sprintf('%d dishes routed against their section.', $routed));
        }
    }
}
