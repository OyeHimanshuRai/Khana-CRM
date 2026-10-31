<?php

namespace Database\Seeders;

use App\Models\Floor;
use App\Models\RestaurantTable;
use App\Services\TableQrService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;

/**
 * A room to work in: three dining areas, their tables and a QR code each.
 *
 * Laid out rather than scattered. A floor plan seeded with random
 * coordinates looks like a bug, and the whole point of the screen is that it
 * reads like the room - so tables go down in rows, the way a restaurant
 * actually arranges them.
 *
 * Statuses are mixed on purpose: a demo where every table is Available shows
 * nothing about what the plan is for, and the occupancy figure on the
 * dashboard would sit at zero.
 */
class DemoDiningSeeder extends Seeder
{
    use SeedsDemoData;

    /**
     * The areas, and how many tables each gets.
     *
     * @var array<int, array{name: string, code: string, tables: int, seats: array<int, int>, note: string}>
     */
    private const AREAS = [
        [
            'name' => 'Ground Floor',
            'code' => 'GF',
            'tables' => 10,
            'seats' => [2, 4, 4, 6],
            'note' => 'Main dining hall, air-conditioned',
        ],
        [
            'name' => 'Rooftop',
            'code' => 'RT',
            'tables' => 8,
            'seats' => [4, 4, 6, 8],
            'note' => 'Open-air, opens at 6pm',
        ],
        [
            'name' => 'Family Cabins',
            'code' => 'FC',
            'tables' => 6,
            'seats' => [4, 6, 6, 8],
            'note' => 'Curtained cabins, minimum four covers',
        ],
    ];

    public function run(): void
    {
        $this->seedRandom(41);

        if (CurrentShop::id() === null) {
            $this->say('Dining: no shop in context, skipped.');

            return;
        }

        if ($this->alreadySeeded('Dining areas', Floor::count(), count(self::AREAS))) {
            return;
        }

        $qrs = app(TableQrService::class);
        $tables = 0;

        foreach (self::AREAS as $index => $area) {
            $floor = Floor::query()->firstOrCreate(
                ['shop_id' => CurrentShop::id(), 'code' => $area['code']],
                [
                    'name' => $area['name'],
                    'description' => $area['note'],
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );

            $tables += $this->tablesFor($floor, $area, $qrs);
        }

        $this->say(sprintf('%d dining areas, %d tables, %d QR codes.', count(self::AREAS), $tables, $tables));
    }

    /**
     * @param  array{tables: int, seats: array<int, int>}  $area
     */
    private function tablesFor(Floor $floor, array $area, TableQrService $qrs): int
    {
        $created = 0;

        /*
         | Five to a row, which is what fits the canvas at the tile's own
         | width without the plan needing a scrollbar on a laptop. Spread
         | across the middle 80% so nothing sits under the canvas edge.
         */
        $perRow = 5;

        for ($n = 1; $n <= $area['tables']; $n++) {
            $code = sprintf('%s-%02d', $floor->code, $n);

            if (RestaurantTable::query()->where('code', $code)->exists()) {
                continue;
            }

            $column = ($n - 1) % $perRow;
            $row = intdiv($n - 1, $perRow);

            $table = new RestaurantTable([
                'shop_id' => $floor->shop_id,
                'floor_id' => $floor->id,
                'name' => (string) $n,
                'code' => $code,
                'capacity' => $this->pick($area['seats']),
                'status' => $this->status(),
                'pos_x' => round(12 + $column * (76 / max(1, $perRow - 1)), 3),
                'pos_y' => round(18 + $row * 26, 3),
                'is_active' => true,
                'sort_order' => $n,
            ]);

            $table->save();

            // Every table gets a code, the same as the create screen does.
            $qrs->issue($table, 'Issued with the table');

            $created++;
        }

        return $created;
    }

    /**
     * A believable Saturday: about half the room seated, a few waiting to be
     * cleared, one or two held for bookings.
     */
    private function status(): string
    {
        return $this->pick([
            RestaurantTable::AVAILABLE,
            RestaurantTable::AVAILABLE,
            RestaurantTable::AVAILABLE,
            RestaurantTable::AVAILABLE,
            RestaurantTable::OCCUPIED,
            RestaurantTable::OCCUPIED,
            RestaurantTable::OCCUPIED,
            RestaurantTable::BILLING,
            RestaurantTable::RESERVED,
            RestaurantTable::CLEANING,
        ]);
    }
}
