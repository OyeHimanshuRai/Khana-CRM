<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogController extends Controller
{
    /** Page sizes offered by the "entries per page" control. */
    private const PAGE_SIZES = [10, 20, 50, 100];

    /*
     | Whose history this reader may see.
     |
     | `settings.activity_logs.*` is granted to a Tenant Owner on purpose - an
     | owner has to be able to see who voided which bill. What it must not do
     | is answer that question for every other business on the platform, which
     | is what this screen did while the table had no owner column: staff
     | names, email addresses, IP addresses and a description of every action
     | another restaurant took.
     |
     | Null tenant_id is the platform's own history - the scheduler, the
     | installer, a console command - and stays with the Super Admin, who is
     | the only account that can act on it.
     */
    private function readable(): Builder
    {
        $query = ActivityLog::query();

        if (auth()->user()?->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('tenant_id', CurrentTenant::accessibleIds() ?: [0]);
    }

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 20;

        $logs = $this->readable()
            ->with('user')
            ->search($request->string('q')->toString())
            ->when($request->string('event')->toString(), fn ($q, $event) => $q->where('event', $event))
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'logs' => $logs,
            // The filter offers the events in this reader's own history; a
            // list naming events only another business ever triggered is a
            // leak in a dropdown.
            'events' => $this->readable()->distinct()->orderBy('event')->pluck('event'),
            'search' => $request->string('q')->toString(),
            'event' => $request->string('event')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
        ];

        // See UserController::index() - fragment for the AJAX list controller.
        return $request->header('X-Fragment')
            ? view('admin.activity._list', $data)
            : view('admin.activity.index', $data);
    }

    /**
     * Stream the filtered log as CSV.
     *
     * Streamed rather than built in memory so a long audit history does not
     * have to fit in one string.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->readable()
            ->search($request->string('q')->toString())
            ->when($request->string('event')->toString(), fn ($q, $event) => $q->where('event', $event))
            ->latest();

        $filename = 'activity-log-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, ['Date', 'User', 'Event', 'Description', 'IP address']);

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [
                        $row->created_at?->toDateTimeString(),
                        $row->user_name,
                        $row->event,
                        $row->description,
                        $row->ip_address,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $days = (int) $request->integer('older_than_days', 90);
        $cutoff = now()->subDays(max($days, 1));

        /*
         | Pruning is the same right as reading, and it has to be the same
         | rows. One customer tidying their own log to ninety days took every
         | other business's history with it.
         */
        $deleted = $this->readable()->where('created_at', '<', $cutoff)->delete();

        ActivityLog::record('activity.pruned', "Pruned {$deleted} log entries older than {$days} days");

        return back()->with('status', "Removed {$deleted} log entr".($deleted === 1 ? 'y' : 'ies').'.');
    }
}
