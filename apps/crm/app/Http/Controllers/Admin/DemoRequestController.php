<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DemoRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The demo-request inbox (§19).
 *
 * ---------------------------------------------------------------------------
 * This is a sales queue, not a content list
 * ---------------------------------------------------------------------------
 *
 * Which is why it opens on "new" rather than on everything. A page of leads
 * sorted by date, with the answered ones mixed into the unanswered, is a page
 * where the one that came in an hour ago sits under thirty that were closed
 * last month. The only list that matters at nine in the morning is the one
 * somebody still has to ring.
 *
 * Nothing here edits what the enquirer typed. The only writes are a status, a
 * note, and a delete - what somebody said they wanted is a record, and a lead
 * whose details can be quietly rewritten is no longer evidence of anything.
 */
class DemoRequestController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        /*
         | Defaults to "new" the first time the screen is opened, and respects
         | an explicit choice after that - including "all", which is how
         | somebody clears the default without it snapping back.
         */
        $status = $request->has('status')
            ? $request->string('status')->toString()
            : DemoRequest::NEW;

        $data = [
            'rows' => DemoRequest::query()
                ->with('handler:id,name')
                ->search($request->string('q')->toString())
                ->ofStatus($status)
                ->latest('id')
                ->paginate($perPage)
                ->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $status,
            'statuses' => DemoRequest::STATUSES,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'new' => DemoRequest::query()->unhandled()->count(),
                'total' => DemoRequest::query()->count(),
                'converted' => DemoRequest::query()->where('status', DemoRequest::CONVERTED)->count(),
                /*
                 | The oldest thing nobody has answered. A count says how much
                 | there is; this says how bad it has got.
                 */
                'oldest_hours' => optional(
                    DemoRequest::query()->unhandled()->oldest('created_at')->first()
                )?->waitingHours(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.demo-requests._list', $data)
            : view('admin.demo-requests.index', $data);
    }

    public function show(DemoRequest $demoRequest): View
    {
        return view('admin.demo-requests._show', [
            'row' => $demoRequest->load('handler:id,name'),
            'statuses' => DemoRequest::STATUSES,
        ]);
    }

    /**
     * Move it along the queue, and optionally leave a note.
     *
     * `handled_by` is stamped the first time it leaves "new", and then left
     * alone: it answers "who picked this up", and somebody later marking it
     * converted should not take the credit for the call.
     */
    public function update(Request $request, DemoRequest $demoRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(DemoRequest::STATUSES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $wasNew = $demoRequest->status === DemoRequest::NEW;

        $demoRequest->forceFill([
            'status' => $data['status'],
            'note' => $data['note'] ?? $demoRequest->note,
            'handled_by' => $wasNew && $data['status'] !== DemoRequest::NEW
                ? $request->user()?->id
                : $demoRequest->handled_by,
            'handled_at' => $wasNew && $data['status'] !== DemoRequest::NEW
                ? now()
                : $demoRequest->handled_at,
        ])->save();

        ActivityLog::record(
            'demo_request.updated',
            sprintf('Marked a demo request %s', $demoRequest->statusLabel()),
            $demoRequest,
            ['name' => $demoRequest->name],
        );

        return ApiResponse::success('Demo request updated.');
    }

    public function destroy(DemoRequest $demoRequest): JsonResponse
    {
        $name = $demoRequest->name;

        $demoRequest->delete();

        ActivityLog::record('demo_request.deleted', 'Deleted a demo request', null, ['name' => $name]);

        return ApiResponse::success('Demo request deleted.');
    }

    /**
     * The queue as a spreadsheet.
     *
     * The message is included here, unlike the support desk's export: an
     * enquiry is a few lines somebody typed about their own restaurant, not a
     * thread where people paste card numbers, and the whole point of exporting
     * leads is to work them somewhere else.
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = DemoRequest::query()
            ->with('handler:id,name')
            ->search($request->string('q')->toString())
            ->ofStatus($request->string('status')->toString())
            ->latest('id')
            ->lazy(500);

        $name = 'demo-requests-'.now()->format('Y-m-d').'.csv';

        return Response::streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Received', 'Name', 'Business', 'City', 'Outlets',
                'Email', 'Phone', 'Status', 'Handled by', 'Message',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->created_at?->format('Y-m-d H:i'),
                    $row->name,
                    $row->business_name,
                    $row->city,
                    $row->outlets,
                    $row->email,
                    $row->phone,
                    $row->statusLabel(),
                    $row->handler?->name,
                    $row->message,
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
