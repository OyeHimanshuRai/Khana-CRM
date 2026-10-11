<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\CampaignService;
use App\Services\Sms\SmsManager;
use App\Services\Whatsapp\WhatsAppManager;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Marketing messages (SRS 15, 21).
 *
 * ---------------------------------------------------------------------------
 * The audience count is the whole screen
 * ---------------------------------------------------------------------------
 *
 * Everything else here is a form. The one control that matters is the number
 * that says how many people a segment reaches, checked before anybody
 * commits - because a campaign sent to the wrong list cannot be recalled, and
 * "214 people" is the only thing that catches it in time.
 *
 * So `preview` is its own endpoint, and the form asks it on every change.
 */
class CampaignController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly CampaignService $campaigns) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $rows = Campaign::query()
            ->with('creator:id,name')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'campaigns' => $rows,
            'status' => $request->string('status')->toString(),
            'statuses' => Campaign::STATUSES,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'channels' => $this->channelStatus(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.campaigns._list', $data)
            : view('admin.campaigns.index', $data);
    }

    /**
     * Which channels could actually send right now.
     *
     * Shown on the screen rather than discovered on send: a campaign written,
     * scheduled and then refused because nobody set up a provider is twenty
     * minutes wasted for a reason that was knowable at the start.
     *
     * @return array<string, bool>
     */
    private function channelStatus(): array
    {
        return [
            'sms' => app(SmsManager::class)->isLive(),
            'whatsapp' => app(WhatsAppManager::class)->isLive(),
        ];
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.campaigns._form', [
            'campaign' => new Campaign(['channel' => 'sms', 'status' => Campaign::DRAFT]),
            'channels' => $this->channelStatus(),
        ]);
    }

    public function edit(Campaign $campaign): View
    {
        abort_unless($campaign->isEditable(), 403, 'That campaign has already gone out.');

        return view('admin.campaigns._form', [
            'campaign' => $campaign,
            'channels' => $this->channelStatus(),
        ]);
    }

    public function show(Campaign $campaign): View
    {
        return view('admin.campaigns._show', [
            'campaign' => $campaign->load('creator:id,name'),
            /*
             | Failures first, then whatever is still waiting.
             |
             | Sorted in PHP rather than with FIELD(), which is MySQL's alone
             | and would make this screen fatal on any other database. The cap
             | is 200 rows, so the sort costs nothing.
             */
            'recipients' => $campaign->recipients()
                ->with('customer:id,name')
                ->limit(200)
                ->get()
                ->sortBy(fn (CampaignRecipient $row) => array_search($row->status, [
                    CampaignRecipient::FAILED,
                    CampaignRecipient::PENDING,
                    CampaignRecipient::SENT,
                    CampaignRecipient::SKIPPED,
                ], true))
                ->values(),
        ]);
    }

    /**
     * How many people this segment reaches.
     *
     * The one number that stops a campaign going to the wrong list.
     */
    public function preview(Request $request): JsonResponse
    {
        $segment = $this->segment($request);

        $count = $this->campaigns->audienceCount($segment);

        return ApiResponse::success('', [
            'count' => $count,
            'label' => match (true) {
                $count === 0 => 'Nobody matches this — widen it, or check the customer records.',
                $count === 1 => 'This will go to 1 person.',
                default => 'This will go to '.number_format($count).' people.',
            },
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $campaign = Campaign::create($data + [
            'shop_id' => CurrentShop::idForWrite(),
            'status' => Campaign::DRAFT,
            'created_by' => $request->user()?->id,
        ]);

        ActivityLog::record('campaign.created', "Drafted campaign \"{$campaign->name}\"", $campaign);

        return ApiResponse::success("\"{$campaign->name}\" saved as a draft. Nothing has been sent.");
    }

    public function update(Request $request, Campaign $campaign): JsonResponse
    {
        if (! $campaign->isEditable()) {
            return ApiResponse::error('That campaign has already gone out and cannot be changed.');
        }

        $campaign->fill($this->validated($request))->save();

        return ApiResponse::success('Campaign updated.');
    }

    /**
     * Freeze the audience and queue it.
     *
     * Gated on `approve` rather than `edit`: writing a draft costs nothing,
     * and sending to four hundred people is not recallable.
     */
    public function send(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'scheduled_for' => ['nullable', 'date'],
        ]);

        try {
            $queued = $this->campaigns->schedule(
                $campaign,
                filled($data['scheduled_for'] ?? null) ? Carbon::parse($data['scheduled_for']) : null,
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record(
            'campaign.scheduled',
            sprintf('Queued "%s" to %d people', $campaign->name, $queued->audience_count),
            $campaign,
        );

        return ApiResponse::success(sprintf(
            '%s queued to %s people. It goes out %s.',
            $queued->name,
            number_format($queued->audience_count),
            $queued->scheduled_for
                ? 'from '.$queued->scheduled_for->format('j M, g:i a')
                : 'on the next send, within ten minutes',
        ));
    }

    public function cancel(Campaign $campaign): JsonResponse
    {
        try {
            $this->campaigns->cancel($campaign);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        ActivityLog::record('campaign.cancelled', "Cancelled campaign \"{$campaign->name}\"", $campaign);

        return ApiResponse::success('Stopped. Anything not yet sent will not go.');
    }

    public function destroy(Campaign $campaign): JsonResponse
    {
        if ($campaign->status === Campaign::SENDING) {
            return ApiResponse::error('That campaign is going out right now. Stop it first.');
        }

        $name = $campaign->name;
        $campaign->delete();

        ActivityLog::record('campaign.deleted', "Deleted campaign \"{$name}\"", $campaign);

        return ApiResponse::success("\"{$name}\" deleted.");
    }

    /* -------------------------------------------------------- validation */

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', Rule::in(Campaign::CHANNELS)],
            /*
             | 480 characters is three SMS segments. Longer is allowed but the
             | form says what it costs, because a marketing message nobody
             | told the restaurant was three messages is a bill nobody
             | expected.
             */
            'body' => ['required', 'string', 'max:2000'],
            'template' => ['nullable', 'string', 'max:120'],
        ]);

        $data['segment'] = $this->segment($request);

        return $data;
    }

    /**
     * The audience rules, cleaned.
     *
     * Empty values are dropped rather than stored as nulls, so a segment
     * reads as the handful of rules somebody actually set - and an empty
     * segment is honestly empty, which means everybody.
     *
     * @return array<string, mixed>
     */
    private function segment(Request $request): array
    {
        return array_filter([
            'visited_within_days' => $request->integer('visited_within_days') ?: null,
            'not_visited_for_days' => $request->integer('not_visited_for_days') ?: null,
            'min_spend' => $request->float('min_spend') ?: null,
            'min_visits' => $request->integer('min_visits') ?: null,
            'has_points' => $request->boolean('has_points') ?: null,
        ], fn ($value) => $value !== null);
    }
}
