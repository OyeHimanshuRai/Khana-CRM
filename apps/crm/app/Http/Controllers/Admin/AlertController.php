<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\User;
use App\Services\AlertService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The notification bell (SRS 15).
 *
 * Two things worth stating about what this shows.
 *
 * It reaches across branches. Everywhere else in this system a shop-scoped
 * default is right; here it is wrong. A manager covering three shops needs to
 * know one of them has an overdue loan without switching into it to find out -
 * so the bell reads every branch on their pivot, and each row says which.
 *
 * And it filters by right, not only by address. An unaddressed alert carries
 * the permission that gates it, because SRS 15's own table is written in roles:
 * "Low stock -> Branch Admin / Warehouse". A cashier does not need to know a
 * a till is still open, and the badge count has to agree with the list that opens
 * beneath it - a number that does not match what you then find is worse than
 * no number.
 *
 * No permission guards these routes. The alert's own `can` does the gating, and
 * a user with no rights simply sees an empty bell rather than a 403 on the
 * header of every page.
 */
class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    /**
     * The dropdown under the bell.
     *
     * A fragment, injected into the header. Deliberately small: the full list
     * is a page, and a dropdown that scrolls is a dropdown nobody reads.
     */
    public function bell(Request $request): View
    {
        $user = $this->user();

        return view('admin.partials.alerts', [
            'alerts' => Alert::acrossShopsFor($user, 10),
            'unread' => Alert::badgeCount($user),
        ]);
    }

    /** Everything, paginated, with filters. */
    public function index(Request $request): View
    {
        $user = $this->user();

        $alerts = Alert::acrossShopsFor($user, 200)
            ->when(
                $request->string('type')->toString(),
                fn ($rows, string $type) => $rows->where('type', $type)
            )
            ->when(
                $request->boolean('unread'),
                fn ($rows) => $rows->filter(fn (Alert $alert) => ! $alert->isRead())
            );

        return view('admin.alerts.index', [
            'alerts' => $alerts->values(),
            'types' => Alert::TYPES,
            'type' => $request->string('type')->toString(),
            'unreadOnly' => $request->boolean('unread'),
            'unread' => Alert::badgeCount($user),
        ]);
    }

    public function read(Alert $alert): JsonResponse
    {
        $user = $this->user();

        /*
         | An alert somebody cannot see is an alert they cannot mark read. The
         | route has no permission middleware - the gating is per row - so the
         | check has to happen here rather than being assumed.
         */
        if (! $alert->isVisibleTo($user)) {
            return ApiResponse::error('That notification is not yours to dismiss.');
        }

        $this->alerts->markRead($alert);

        return ApiResponse::success('', [
            'unread' => Alert::badgeCount($user),
        ]);
    }

    public function readAll(): JsonResponse
    {
        $user = $this->user();

        $count = $this->alerts->markAllRead($user);

        return ApiResponse::success(
            $count === 0 ? 'Nothing to clear.' : "Cleared {$count} notification".($count === 1 ? '' : 's').'.',
            ['unread' => 0],
        );
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
