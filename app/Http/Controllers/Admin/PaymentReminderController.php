<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PaymentReminder;
use App\Services\ReminderService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reminder log: what the shop has told its customers about money owed,
 * and what it is about to.
 *
 * Read-mostly. The three write actions are the ones a person genuinely needs
 * when the automatic run did not do what they wanted: send one now, retry a
 * failure, cancel one that should not go out.
 */
class PaymentReminderController extends Controller
{
    private const PAGE_SIZES = [15, 25, 50, 100];

    public function __construct(private readonly ReminderService $reminders) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25;

        $reminders = $this->filtered($request)
            ->with(['customer:id,name,mobile,email', 'invoice:id,number,due_total,due_date', 'shop:id,name'])
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'reminders' => $reminders,
            'search' => $request->string('q')->toString(),
            'trigger' => $request->string('trigger')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'triggers' => config('reminders.triggers', []),
            'statuses' => PaymentReminder::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'enabled' => (bool) config('reminders.enabled', true),
            'stats' => [
                'queued' => PaymentReminder::where('status', PaymentReminder::PENDING)->count(),
                'sent' => PaymentReminder::where('status', PaymentReminder::SENT)->count(),
                'failed' => PaymentReminder::where('status', PaymentReminder::FAILED)->count(),
                'chasing' => (float) PaymentReminder::whereIn('status', [
                    PaymentReminder::PENDING, PaymentReminder::SENT,
                ])->sum('amount_due'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.reminders._list', $data)
            : view('admin.reminders.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return PaymentReminder::query()
            ->search($request->string('q')->toString())
            ->ofTrigger($request->string('trigger')->toString())
            ->ofStatus($request->string('status')->toString())
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id');
    }

    public function show(PaymentReminder $reminder): View
    {
        return view('admin.reminders._show', [
            'reminder' => $reminder->load(['customer', 'invoice', 'shop']),
        ]);
    }

    /* ----------------------------------------------------------- actions */

    /**
     * Send one now, without waiting for the next run.
     *
     * Goes through the same path as the scheduled send, so all the
     * last-moment checks still apply - including "the customer has already
     * paid", which is the most common reason a hand-sent reminder should
     * not go out.
     */
    public function send(PaymentReminder $reminder): JsonResponse
    {
        if (! in_array($reminder->status, [PaymentReminder::PENDING, PaymentReminder::FAILED], true)) {
            return ApiResponse::error(
                'Only a queued or failed reminder can be sent. This one is '
                .strtolower($reminder->statusLabel()).'.'
            );
        }

        $outcome = $this->reminders->send($reminder);

        return match ($outcome) {
            'sent' => ApiResponse::success("Reminder sent to {$reminder->recipient}."),
            'skipped' => ApiResponse::success(
                'Nothing sent — '.strtolower((string) $reminder->fresh()->skip_reason).'.'
            ),
            default => ApiResponse::error(
                'The reminder could not be sent: '.($reminder->fresh()->last_error ?: 'unknown error')
            ),
        };
    }

    /**
     * Stop a queued reminder going out.
     *
     * Cancelled rather than deleted: the row is the evidence that the shop
     * decided not to chase, which is sometimes exactly what needs proving.
     */
    public function cancel(PaymentReminder $reminder): JsonResponse
    {
        if ($reminder->status !== PaymentReminder::PENDING) {
            return ApiResponse::error('Only a queued reminder can be cancelled.');
        }

        $reminder->forceFill([
            'status' => PaymentReminder::CANCELLED,
            'skip_reason' => 'Cancelled by '.(auth()->user()?->name ?? 'an administrator'),
        ])->save();

        ActivityLog::record(
            'reminder.cancelled',
            "Cancelled the {$reminder->triggerLabel()} reminder for ".($reminder->customer?->name ?? 'a customer'),
            $reminder,
        );

        return ApiResponse::success('Reminder cancelled. It will not be sent.');
    }

    /**
     * Run the scheduler by hand.
     *
     * Useful on the day a shop first loads its outstanding invoices, and for
     * anyone who wants to see what the nightly run would do.
     */
    public function runScheduler(): JsonResponse
    {
        $result = $this->reminders->schedule();

        return ApiResponse::success(sprintf(
            'Looked at %d outstanding invoice(s) and scheduled %d new reminder(s).',
            $result['considered'],
            $result['scheduled'],
        ));
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'reminders-'.now()->format('Y-m-d-His').'.csv';

        $columns = [
            'Scheduled', 'Sent', 'Shop', 'Customer', 'Invoice', 'Due date',
            'Amount', 'Trigger', 'Channel', 'Recipient', 'Status', 'Attempts', 'Note',
        ];

        $query = $this->filtered($request)->with(['customer', 'invoice', 'shop']);

        ActivityLog::record('reminder.exported', 'Exported the reminder log');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(500, function ($chunk) use ($handle) {
                foreach ($chunk as $reminder) {
                    fputcsv($handle, [
                        $reminder->scheduled_for?->format('Y-m-d H:i'),
                        $reminder->sent_at?->format('Y-m-d H:i'),
                        $reminder->shop?->name,
                        $reminder->customer?->name,
                        $reminder->invoice?->number,
                        $reminder->due_date?->format('Y-m-d'),
                        number_format((float) $reminder->amount_due, 2, '.', ''),
                        $reminder->triggerLabel(),
                        $reminder->channelLabel(),
                        $reminder->recipient,
                        $reminder->statusLabel(),
                        $reminder->attempts,
                        $reminder->skip_reason ?: $reminder->last_error,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
