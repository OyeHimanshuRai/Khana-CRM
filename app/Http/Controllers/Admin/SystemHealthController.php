<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\SystemHealthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Is anything quietly broken? (§17)
 *
 * ---------------------------------------------------------------------------
 * Platform staff only
 * ---------------------------------------------------------------------------
 *
 * Everything on this screen is about the server rather than the business:
 * failed job classes, the tail of the error log, disk usage, which drivers are
 * configured. A restaurant owner has no use for any of it and every line of it
 * helps somebody map the deployment, which is why `settings.health.*` is
 * granted to Super Admin and Admin and to nobody else - see the note in
 * config/permissions.php.
 *
 * ---------------------------------------------------------------------------
 * Reading is separate from doing
 * ---------------------------------------------------------------------------
 *
 * `view` opens the screen. Retrying a failed job re-runs whatever it was -
 * which for a payment webhook or a campaign send is a real-world side effect,
 * possibly a second time - so it is its own right, and so is discarding one.
 */
class SystemHealthController extends Controller
{
    public function __construct(private readonly SystemHealthService $health) {}

    public function index(Request $request): View
    {
        return view('admin.health.index', [
            'snapshot' => $this->health->snapshot(),
            'failedJobs' => $this->health->failedJobs(),
            'errors' => $this->health->recentErrors(),
        ]);
    }

    /**
     * Run a backup now, off-schedule.
     *
     * Synchronous on purpose. The queue is one of the things this screen exists
     * to tell you is broken, and a backup dispatched onto a queue nobody is
     * working would report "started" and never happen - which is precisely the
     * failure mode §17 is asking to make visible.
     *
     * The command is safe to run at any time: `--single-transaction` means it
     * does not lock the till out. See App\Console\Commands\BackupDatabase.
     */
    public function backup(): JsonResponse
    {
        $exit = Artisan::call('backup:run');
        $output = trim(Artisan::output());

        ActivityLog::record('system.backup', 'Ran a database backup by hand', null);

        return $exit === 0
            ? ApiResponse::success($output ?: 'Backup complete.')
            : ApiResponse::error($output ?: 'The backup failed. Check that mysqldump is on PATH.', status: 500);
    }

    /**
     * Put a failed job back on the queue.
     *
     * By uuid rather than by the table's own id, because that is what
     * `queue:retry` takes and what survives the row being rewritten when the
     * retry itself fails.
     */
    public function retry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'string', 'max:64'],
        ]);

        if (! Schema::hasTable('failed_jobs')) {
            return ApiResponse::error('This queue driver keeps no failed-job table.');
        }

        $exists = DB::table('failed_jobs')->where('uuid', $data['uuid'])->exists();

        if (! $exists) {
            // Usually because somebody else retried it while this page was open.
            return ApiResponse::error('That job is no longer in the failed list.');
        }

        Artisan::call('queue:retry', ['id' => [$data['uuid']]]);

        ActivityLog::record('system.job_retried', 'Retried failed job '.$data['uuid'], null);

        return ApiResponse::success('Job queued to run again.', redirect: route('admin.health.index'));
    }

    /** Put every failed job back on the queue. */
    public function retryAll(): JsonResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ApiResponse::error('This queue driver keeps no failed-job table.');
        }

        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return ApiResponse::error('There is nothing to retry.');
        }

        Artisan::call('queue:retry', ['id' => ['all']]);

        ActivityLog::record('system.jobs_retried', "Retried {$count} failed job(s)", null);

        return ApiResponse::success("{$count} job(s) queued to run again.", redirect: route('admin.health.index'));
    }

    /**
     * Discard failed jobs.
     *
     * Either one, or the lot. Flushing is guarded by a confirmation in the
     * view rather than here: what is being destroyed is the only record of
     * what went wrong, and once it is gone there is nothing to look at.
     */
    public function discard(Request $request): JsonResponse
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ApiResponse::error('This queue driver keeps no failed-job table.');
        }

        $data = $request->validate([
            'uuid' => ['nullable', 'string', 'max:64'],
        ]);

        if (! empty($data['uuid'])) {
            $deleted = DB::table('failed_jobs')->where('uuid', $data['uuid'])->delete();

            ActivityLog::record('system.job_discarded', 'Discarded failed job '.$data['uuid'], null);

            return $deleted
                ? ApiResponse::success('Job discarded.', redirect: route('admin.health.index'))
                : ApiResponse::error('That job is no longer in the failed list.');
        }

        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return ApiResponse::error('There is nothing to discard.');
        }

        DB::table('failed_jobs')->delete();

        ActivityLog::record('system.jobs_flushed', "Discarded all {$count} failed job(s)", null);

        return ApiResponse::success("Discarded {$count} job(s).", redirect: route('admin.health.index'));
    }
}
