<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Feedback;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What guests thought, and what was done about it (SRS 15).
 *
 * ---------------------------------------------------------------------------
 * The default view is the unhappy and unanswered
 * ---------------------------------------------------------------------------
 *
 * A feedback screen that opens on "all, newest first" is a screen of
 * four-stars, which is pleasant and useless. The list somebody needs at ten
 * in the morning is the one they have to do something about, so the poor
 * ratings with no reply are counted separately and are one tap away.
 *
 * Nothing here may edit what a guest said. The only write is a reply.
 */
class FeedbackController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $feedback = Feedback::query()
            ->with(['customer:id,name', 'session.table:id,name', 'responder:id,name'])
            ->when($request->boolean('attention'), fn (Builder $q) => $q->needsAttention())
            ->when($request->string('rating')->toString(), fn (Builder $q, string $r) => $q->where('rating', (int) $r))
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('comment', 'like', $like)
                    ->orWhere('guest_name', 'like', $like)
                    ->orWhere('guest_mobile', 'like', $like));
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'feedback' => $feedback,
            'attention' => $request->boolean('attention'),
            'rating' => $request->string('rating')->toString(),
            'search' => $request->string('q')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.feedback._list', $data)
            : view('admin.feedback.index', $data);
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        $month = Feedback::query()->where('created_at', '>=', now()->subDays(30));

        $count = (clone $month)->count();

        return [
            'count' => $count,
            /*
             | Null rather than zero when nobody has rated anything. "0.0 out
             | of 5" on a restaurant's first week is a lie that reads as a
             | disaster.
             */
            'average' => $count > 0 ? round((float) (clone $month)->avg('rating'), 1) : null,
            'attention' => Feedback::query()->needsAttention()->count(),
            'answered' => (clone $month)->whereNotNull('responded_at')->count(),
        ];
    }

    public function show(Feedback $feedback): View
    {
        return view('admin.feedback._show', [
            'feedback' => $feedback->load(['customer', 'session.table', 'order', 'responder:id,name']),
        ]);
    }

    /**
     * Reply to a guest.
     *
     * The only write on this screen. What the guest said is never editable -
     * a complaint that can be quietly softened is not feedback.
     */
    public function respond(Request $request, Feedback $feedback): JsonResponse
    {
        $data = $request->validate([
            'response' => ['required', 'string', 'max:2000'],
        ]);

        $feedback->forceFill([
            'response' => $data['response'],
            'responded_at' => now(),
            'responded_by' => $request->user()?->id,
        ])->save();

        ActivityLog::record(
            'feedback.answered',
            sprintf('Answered %d-star feedback from %s', $feedback->rating, $feedback->fromLabel()),
            $feedback,
        );

        return ApiResponse::success('Reply saved.');
    }

    public function destroy(Feedback $feedback): JsonResponse
    {
        $from = $feedback->fromLabel();
        $feedback->delete();

        ActivityLog::record('feedback.deleted', "Deleted feedback from {$from}", $feedback);

        return ApiResponse::success('Feedback removed.');
    }
}
