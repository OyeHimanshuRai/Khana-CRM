<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Services\SystemHealthService;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Is anything quietly broken? (§17)
 *
 * The thing worth protecting on this screen is that it *renders*. Every check
 * reads something outside the database - the disk, the log file, the backup
 * directory - and any of them can be missing on a server somebody has just
 * deployed to. A health screen that 500s because there is no log file yet is
 * worse than no health screen, because it fails exactly when it is first
 * needed.
 *
 * After that: that it is platform-only. It prints the tail of the error log
 * and the class name of every failed job, neither of which is a restaurant's
 * business.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Scratch storage, per test.
     *
     * These tests assert on "no backups yet" and "no log file", which means
     * clearing those places - and the first version of this file cleared the
     * real ones and deleted a developer's actual dump. Both paths are config
     * now (see config/backup.php), so the suite points them at a temporary
     * directory it owns and can safely destroy.
     */
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        URL::forceRootUrl('http://localhost');

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'health-'.uniqid();

        config([
            'backup.path' => $this->scratch.DIRECTORY_SEPARATOR.'backups',
            'logging.channels.'.config('logging.default').'.path' => $this->scratch.DIRECTORY_SEPARATOR.'laravel.log',
        ]);

        $this->seed();

        CurrentShop::forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        CurrentShop::forget();

        parent::tearDown();
    }

    /** The scratch log file this test run is pointed at. */
    private function logPath(): string
    {
        return config('logging.channels.'.config('logging.default').'.path');
    }

    /* ---------------------------------------------------------- fixtures */

    private function shop(): Shop
    {
        return Shop::query()->withoutGlobalScopes()->orderBy('id')->firstOrFail();
    }

    /** @param array<int, string> $permissions */
    private function user(array $permissions): User
    {
        $shop = $this->shop();

        $user = User::create([
            'tenant_id' => $shop->tenant_id,
            'name' => 'Ops',
            'email' => 'ops'.uniqid().'@example.test',
            'password' => 'ops-password-1',
            'is_admin' => true,
        ]);

        $user->shops()->syncWithoutDetaching([$shop->id => ['is_default' => true]]);
        $user->forceFill([
            'current_shop_id' => $shop->id,
            'all_shops_view' => false,
        ])->save();

        $user->givePermissionTo(array_merge(['dashboard.overview.view'], $permissions));

        return $user->fresh();
    }

    /** A job that threw, the way the queue records one. */
    private function failJob(string $displayName = 'App\\Jobs\\SendWelcomeEmail'): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName, 'job' => $displayName]),
            'exception' => "RuntimeException: Connection to smtp refused\n#0 /app/vendor/...\n#1 /app/vendor/...",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    /* ------------------------------------------------------------ access */

    public function test_the_screen_is_platform_staff_only(): void
    {
        // A restaurant's own manager, with every operational right they could
        // plausibly hold, still may not read the server's internals.
        $manager = $this->user([
            'sales.invoices.view',
            'reports.sales_report.view',
            'settings.activity_logs.view',
        ]);

        $this->actingAs($manager)
            ->get(route('admin.health.index'))
            ->assertForbidden();
    }

    public function test_platform_staff_can_read_it(): void
    {
        $ops = $this->user(['settings.health.view']);

        $this->actingAs($ops)
            ->get(route('admin.health.index'))
            ->assertOk()
            ->assertSee('System Health')
            ->assertSee('Failed jobs');
    }

    /**
     * It renders on a server where nothing has happened yet.
     *
     * No log file, no backup directory, an empty failed_jobs table. This is a
     * fresh deployment, and it is the first time anybody opens this page.
     */
    public function test_it_renders_on_a_fresh_server(): void
    {
        // Nothing to clear: the scratch paths start empty, which IS a fresh
        // server. That is the point of pointing them somewhere disposable.
        $this->assertFileDoesNotExist($this->logPath());

        $ops = $this->user(['settings.health.view']);

        $this->actingAs($ops)
            ->get(route('admin.health.index'))
            ->assertOk()
            ->assertSee('Never')          // no backup has ever run
            ->assertSee('No errors logged');
    }

    /* -------------------------------------------------------- failed jobs */

    public function test_a_failed_job_is_listed_with_its_reason(): void
    {
        $this->failJob();

        $ops = $this->user(['settings.health.view']);

        $this->actingAs($ops)
            ->get(route('admin.health.index'))
            ->assertOk()
            ->assertSee('SendWelcomeEmail')
            // The first line of the exception, not the stack trace.
            ->assertSee('Connection to smtp refused')
            ->assertDontSee('/app/vendor/');
    }

    public function test_retrying_needs_its_own_right(): void
    {
        $uuid = $this->failJob();

        // `view` alone opens the screen and does nothing else: a retry re-runs
        // whatever the job was, which can be a real-world side effect.
        $reader = $this->user(['settings.health.view']);

        $this->actingAs($reader)
            ->post(route('admin.health.jobs.retry'), ['uuid' => $uuid])
            ->assertForbidden();

        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    public function test_discarding_needs_its_own_right_and_works(): void
    {
        $uuid = $this->failJob();

        $reader = $this->user(['settings.health.view']);

        $this->actingAs($reader)
            ->delete(route('admin.health.jobs.discard'), ['uuid' => $uuid])
            ->assertForbidden();

        $ops = $this->user(['settings.health.view', 'settings.health.delete']);

        $this->actingAs($ops)
            ->delete(route('admin.health.jobs.discard'), ['uuid' => $uuid])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
    }

    /* ------------------------------------------------------ the service */

    /**
     * Only ERROR and above, and one line each.
     *
     * A log full of INFO is how the one line that matters gets missed, and a
     * stack trace in a table cell is unreadable.
     */
    public function test_the_log_reader_keeps_only_real_errors(): void
    {
        $path = $this->logPath();
        File::ensureDirectoryExists(dirname($path));

        File::put($path, implode("\n", [
            '[2026-09-17 05:00:00] production.INFO: Nothing much happened',
            '[2026-09-17 05:01:00] production.DEBUG: Chatter',
            '[2026-09-17 05:02:00] production.ERROR: Payment webhook rejected',
            '#0 /app/vendor/laravel/framework/src/Foo.php(12)',
            '#1 /app/vendor/laravel/framework/src/Bar.php(34)',
            '[2026-09-17 05:03:00] production.CRITICAL: Database has gone away',
            '',
        ]));

        $errors = app(SystemHealthService::class)->recentErrors();

        $this->assertCount(2, $errors);

        // Newest first.
        $this->assertSame('CRITICAL', $errors[0]['level']);
        $this->assertSame('Database has gone away', $errors[0]['message']);

        $this->assertSame('ERROR', $errors[1]['level']);
        $this->assertSame('Payment webhook rejected', $errors[1]['message']);
    }

    /**
     * A backup directory with nothing in it is a louder problem than none at
     * all: somebody set it up and it stopped working.
     */
    public function test_an_empty_backup_directory_reads_as_a_fault(): void
    {
        $directory = config('backup.path');
        File::ensureDirectoryExists($directory);

        $backups = app(SystemHealthService::class)->backups();

        $this->assertSame(0, $backups['count']);
        $this->assertSame('danger', $backups['tone']);
    }

    /** The backup command refuses rather than pretends on a non-MySQL box. */
    public function test_the_backup_command_is_a_no_op_on_sqlite(): void
    {
        // The suite runs on SQLite, so this is the real path here - and it
        // must exit 0, or every CI run fails on a command that did the right
        // thing.
        $this->artisan('backup:run')
            ->expectsOutputToContain('not mysql')
            ->assertExitCode(0);
    }
}
