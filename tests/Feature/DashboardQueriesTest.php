<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The dashboard's stock tile and its alert list read the same figures; each
 * must be worked out once per request, not once per place that shows it.
 */
class DashboardQueriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_figures_are_queried_once_per_request(): void
    {
        URL::forceRootUrl('http://localhost');
        $this->seed();
        CurrentShop::forget();
        CurrentTenant::forget();

        $this->actingAs(User::query()->withoutGlobalScopes()->where('email', 'admin@erp.test')->firstOrFail());
        $this->get('/superadmin/dashboard')->assertOk(); // warm caches

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/superadmin/dashboard')->assertOk();
        $sql = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        // Near-expiry and expired: one query each, shared by tile and alert.
        $this->assertSame(2, $sql->filter(fn ($q) => str_contains($q, 'from "batches"'))->count());
        // Low stock.
        $this->assertSame(1, $sql->filter(fn ($q) => str_contains($q, '"reorder_level" >'))->count());
        // Overdue invoice count.
        $this->assertSame(1, $sql->filter(fn ($q) => str_contains($q, 'select count(*)')
            && str_contains($q, 'from "invoices"')
            && str_contains($q, '"due_date" <'))->count());
    }
}
