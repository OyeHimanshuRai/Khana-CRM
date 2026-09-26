<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Support\ApiResponse;
use App\Support\ImageGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Events, end to end without a page reload.
 *
 * Same shape as the other content modules: a fragment for ajax-list.js,
 * fragments for modal.js, the JSON envelope for every write.
 */
class EventController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const IMAGE_DIR = 'events';

    /** The image slot, shared with the guards and the form. */
    private const IMAGE_SLOT = [
        'image' => [
            'label' => 'Event image',
            'max_width' => Event::IMAGE_MAX_WIDTH,
            'max_height' => Event::IMAGE_MAX_HEIGHT,
        ],
    ];

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'events' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'phase' => $request->string('phase')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'soonest',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Event::count(),
                'active' => Event::where('is_active', true)->count(),
                'upcoming' => Event::upcoming()->count(),
                'with_image' => Event::whereNotNull('image_path')->count(),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.events._list', $data)
            : view('admin.events.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        $today = now()->toDateString();

        return Event::query()
            ->search($request->string('q')->toString())
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->when($request->string('phase')->toString(), function (Builder $query, string $phase) use ($today) {
                // Calendar position, which is separate from the on/off switch.
                match ($phase) {
                    'past' => $query->whereNotNull('to_date')->whereDate('to_date', '<', $today),
                    'upcoming' => $query->whereNotNull('from_date')->whereDate('from_date', '>', $today),
                    'running' => $query
                        ->where(fn (Builder $q) => $q->whereNull('from_date')->orWhereDate('from_date', '<=', $today))
                        ->where(fn (Builder $q) => $q->whereNull('to_date')->orWhereDate('to_date', '>=', $today))
                        ->where(fn (Builder $q) => $q->whereNotNull('from_date')->orWhereNotNull('to_date')),
                    default => $query,
                };
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'latest_date' => $query->orderByRaw('from_date IS NULL, from_date DESC'),
                    'title_asc' => $query->orderBy('title'),
                    'title_desc' => $query->orderByDesc('title'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    // Nulls last, so an undated event is not read as imminent.
                    default => $query->orderByRaw('from_date IS NULL, from_date ASC')->orderBy('id'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.events._form', ['event' => new Event()]);
    }

    public function edit(Event $event): View
    {
        return view('admin.events._form', ['event' => $event]);
    }

    public function show(Event $event): View
    {
        return view('admin.events._show', ['event' => $event]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $event = new Event([
            'title' => $data['title'],
            'name' => $data['name'] ?? null,
            'timing' => $data['timing'] ?? null,
            'from_date' => $data['from_date'] ?? null,
            'to_date' => $data['to_date'] ?? null,
            'booth_no' => $data['booth_no'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if ($request->hasFile('image')) {
            $event->image_path = $request->file('image')->store(self::IMAGE_DIR, 'public');
        }

        $event->save();

        ActivityLog::record('event.created', "Created event \"{$event->title}\"", $event);

        return ApiResponse::success("Event \"{$event->title}\" created.", $this->payload($event));
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $this->validated($request);

        $event->fill([
            'title' => $data['title'],
            'name' => $data['name'] ?? null,
            'timing' => $data['timing'] ?? null,
            'from_date' => $data['from_date'] ?? null,
            'to_date' => $data['to_date'] ?? null,
            'booth_no' => $data['booth_no'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        if ($request->hasFile('image')) {
            $previous = $event->image_path;
            $event->image_path = $request->file('image')->store(self::IMAGE_DIR, 'public');

            // Dropped only once the replacement is on disk, so a failed
            // write cannot leave the row pointing at nothing.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $event->save();

        ActivityLog::record('event.updated', "Updated event \"{$event->title}\"", $event);

        return ApiResponse::success("Event \"{$event->title}\" updated.", $this->payload($event));
    }

    public function destroy(Event $event): JsonResponse
    {
        $title = $event->title;
        $image = $event->image_path;

        $event->delete();

        if (filled($image)) {
            Storage::disk('public')->delete($image);
        }

        ActivityLog::record('event.deleted', "Deleted event \"{$title}\"");

        return ApiResponse::success("Event \"{$title}\" deleted.");
    }

    public function toggleStatus(Event $event): JsonResponse
    {
        $active = ! $event->is_active;

        $event->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'event.activated' : 'event.deactivated',
            ($active ? 'Activated' : 'Deactivated')." event \"{$event->title}\"",
            $event,
        );

        return ApiResponse::success(
            "\"{$event->title}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyImage(Event $event): JsonResponse
    {
        if (blank($event->image_path)) {
            return ApiResponse::success('There was no image to remove.', ['image' => null]);
        }

        $path = $event->image_path;
        $event->forceFill(['image_path' => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record('event.image_removed', "Removed the image for \"{$event->title}\"", $event);

        return ApiResponse::success('Image removed.', ['image' => null]);
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'name' => ['nullable', 'string', 'max:180'],
            'timing' => ['nullable', 'string', 'max:120'],
            'from_date' => ['nullable', 'date'],
            // An end before the start is a typo, not a valid range.
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
            'booth_no' => ['nullable', 'string', 'max:60'],
            'is_active' => ['boolean'],

            /*
             | SVG is on the format list the brief asked for, so `image` is
             | not usable here - it rejects SVG outright. mimes and mimetypes
             | together stop a renamed file riding in on its extension.
             */
            'image' => [
                'nullable',
                'file',
                'mimes:svg,png,jpg,jpeg',
                'mimetypes:image/svg+xml,image/png,image/jpeg',
                'max:2048',
            ],
        ], [
            'to_date.after_or_equal' => 'The end date cannot be before the start date.',
            'image.mimes' => 'Use an SVG, PNG or JPG image.',
            'image.mimetypes' => 'That file is not an SVG, PNG or JPG image.',
            'image.max' => 'The image must be 2 MB or smaller.',
        ]);

        // The pixel cap and the SVG script check; see App\Support\ImageGuard.
        ImageGuard::dimensions($request, self::IMAGE_SLOT);
        ImageGuard::svgContents($request, self::IMAGE_SLOT);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Event $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'phase' => $event->phase(),
            'dates' => $event->dateRange(),
            'is_active' => $event->is_active,
            'image' => $event->imageUrl(),
        ];
    }
}
