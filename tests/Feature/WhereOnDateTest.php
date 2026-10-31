<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * whereOnDate() must give exactly the answer whereDate() gave - it only
 * changes how the database finds it. See AppServiceProvider.
 *
 * Checked against every operator the app uses, on a timestamp column and on
 * a date column, with dates stored both as '2026-10-24' and as
 * '2026-10-24 00:00:00', because SQLite keeps whichever it was handed.
 */
class WhereOnDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('date_probe', function (Blueprint $table) {
            $table->id();
            $table->timestamp('at')->nullable();
            $table->date('on')->nullable();
        });

        DB::table('date_probe')->insert([
            ['at' => '2026-10-23 23:59:59', 'on' => '2026-10-23'],
            ['at' => '2026-10-24 00:00:00', 'on' => '2026-10-24'],
            ['at' => '2026-10-24 13:30:00', 'on' => '2026-10-24 00:00:00'],
            ['at' => '2026-10-24 23:59:59', 'on' => '2026-10-24'],
            ['at' => '2026-10-25 00:00:00', 'on' => '2026-10-25'],
            ['at' => null, 'on' => null],
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        $cases = [];

        foreach (['at', 'on'] as $column) {
            foreach (['=', '>=', '>', '<', '<='] as $operator) {
                $cases["{$column} {$operator}"] = [$column, $operator];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_it_agrees_with_where_date(string $column, string $operator): void
    {
        foreach (['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26'] as $day) {
            $old = DB::table('date_probe')->whereDate($column, $operator, $day)->pluck('id')->sort()->values()->all();
            $new = DB::table('date_probe')->whereOnDate($column, $operator, $day)->pluck('id')->sort()->values()->all();

            $this->assertSame($old, $new, "{$column} {$operator} {$day}");
        }
    }

    public function test_two_arguments_mean_equals_and_a_carbon_is_accepted(): void
    {
        $this->assertSame(3, DB::table('date_probe')->whereOnDate('at', '2026-10-24')->count());
        $this->assertSame(3, DB::table('date_probe')->whereOnDate('at', Carbon::parse('2026-10-24 18:00'))->count());
    }

    public function test_it_compares_the_bare_column_so_an_index_can_be_used(): void
    {
        $sql = DB::table('date_probe')->whereOnDate('at', '>=', '2026-10-24')->toSql();

        $this->assertStringNotContainsStringIgnoringCase('date(', $sql);
        $this->assertStringNotContainsStringIgnoringCase('strftime', $sql);
    }

    public function test_the_month_and_year_boundaries_hold(): void
    {
        DB::table('date_probe')->insert([
            ['at' => '2026-12-31 22:00:00', 'on' => '2026-12-31'],
            ['at' => '2027-01-01 00:00:00', 'on' => '2027-01-01'],
        ]);

        $this->assertSame(1, DB::table('date_probe')->whereOnDate('at', '2026-12-31')->count());
        $this->assertSame(1, DB::table('date_probe')->whereOnDate('on', '<=', '2026-12-31')->where('on', '>=', '2026-12-01')->count());
    }

    public function test_something_that_is_not_a_date_behaves_as_it_always_did(): void
    {
        $this->assertSame(
            DB::table('date_probe')->whereDate('at', '>=', 'not-a-date')->count(),
            DB::table('date_probe')->whereOnDate('at', '>=', 'not-a-date')->count(),
        );
    }

    public function test_it_reaches_through_an_eloquent_builder(): void
    {
        $sql = \App\Models\Invoice::query()->whereOnDate('invoiced_at', '2026-10-24')->toSql();

        $this->assertStringContainsString('"invoiced_at" >= ?', $sql);
        $this->assertStringContainsString('"invoiced_at" < ?', $sql);
    }
}
