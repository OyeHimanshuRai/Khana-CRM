<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every email the app has sent, whatever sent it.
 *
 * Read-only by design: the rows are written by the mail listeners, and the
 * only write this screen offers is pruning old ones. Same fragment/modal
 * shape as the other admin modules.
 */
class EmailLogController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    /** Retention offered by the prune control. */
    private const PRUNE_CHOICES = [30, 90, 180, 365];

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'logs' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'statuses' => EmailLog::STATUSES,
            'kinds' => $this->kinds(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'kind' => $request->string('kind')->toString(),
            'period' => $request->string('period')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'newest',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'pruneChoices' => self::PRUNE_CHOICES,
            'stats' => EmailLog::stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.email.logs._list', $data)
            : view('admin.email.logs.index', $data);
    }

    /**
     * Shared query for the listing and its export.
     */
    private function filtered(Request $request): Builder
    {
        return EmailLog::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('status', $status);
            })
            ->when($request->string('kind')->toString(), function (Builder $query, string $kind) {
                // "—" is the filter's stand-in for a raw send with no mailable.
                $kind === '—'
                    ? $query->whereNull('mailable')
                    : $query->where('mailable', $kind);
            })
            ->when($request->string('period')->toString(), function (Builder $query, string $period) {
                $since = match ($period) {
                    'today' => now()->startOfDay(),
                    'week' => now()->subDays(7),
                    'month' => now()->startOfMonth(),
                    'quarter' => now()->subDays(90),
                    default => null,
                };

                if ($since !== null) {
                    $query->where('created_at', '>=', $since);
                }
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'oldest' => $query->oldest(),
                    'recipient' => $query->orderBy('to_email'),
                    default => $query->latest(),
                };
            });
    }

    /**
     * The mailable classes actually present, for the filter.
     *
     * Derived from the rows rather than a hard-coded list, so it cleans
     * itself up and never offers a filter that matches nothing.
     *
     * @return Collection<string, string>
     */
    private function kinds(): Collection
    {
        return EmailLog::query()
            ->whereNotNull('mailable')
            ->distinct()
            ->orderBy('mailable')
            ->pluck('mailable')
            ->mapWithKeys(fn (string $class) => [
                $class => Str::headline(class_basename($class)),
            ]);
    }

    public function show(EmailLog $log): View
    {
        return view('admin.email.logs._show', ['log' => $log]);
    }

    /* ------------------------------------------------------------ export */

    /**
     * Export the current filtered view as CSV.
     *
     * Uses the same query builder as index(), so what downloads always
     * matches what is on screen, filters included.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request);
        $filename = 'email-logs-'.now()->format('Y-m-d-His').'.csv';

        ActivityLog::record('email_log.exported', 'Exported the email delivery log');

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'wb');

            // BOM, so Excel opens accented names as UTF-8 rather than mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'ID', 'Type', 'To', 'Name', 'Subject', 'From', 'Mailer',
                'Status', 'Error', 'Sent at', 'Created at',
            ]);

            $query->chunk(500, function (EloquentCollection $rows) use ($handle) {
                foreach ($rows as $log) {
                    fputcsv($handle, [
                        $log->id,
                        $log->kindLabel(),
                        $log->to_email,
                        $log->to_name,
                        $log->subject,
                        $log->from_email,
                        $log->mailer,
                        $log->statusLabel(),
                        $log->error,
                        $log->sent_at?->toDateTimeString(),
                        $log->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /* ------------------------------------------------------------- prune */

    /**
     * Drop entries older than the chosen retention.
     *
     * A delivery log grows forever and is mostly uninteresting after a few
     * months; this is the manual half of the housekeeping that
     * `email:prune-logs` does on the scheduler.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'older_than_days' => ['required', 'integer', 'in:'.implode(',', self::PRUNE_CHOICES)],
        ], [
            'older_than_days.in' => 'Choose one of the offered retention periods.',
        ]);

        $days = (int) $data['older_than_days'];
        $deleted = EmailLog::where('created_at', '<', now()->subDays($days))->delete();

        ActivityLog::record(
            'email_log.pruned',
            "Pruned {$deleted} email log entr(y/ies) older than {$days} days",
        );

        return ApiResponse::success(
            $deleted === 0
                ? "Nothing was older than {$days} days."
                : "{$deleted} entr".($deleted === 1 ? 'y' : 'ies')." older than {$days} days deleted.",
            ['deleted' => $deleted],
        );
    }
}
