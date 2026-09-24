<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Slider;
use App\Support\ApiResponse;
use App\Support\ImageGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Sliders, end to end without a page reload.
 *
 * Same shape as the other content modules - a fragment for ajax-list.js,
 * fragments for modal.js, the JSON envelope for every write - with four
 * independent media slots instead of one image. Every media loop walks
 * Slider::MEDIA, so the slots are declared once.
 */
class SliderController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    private const MEDIA_DIR = 'sliders';

    /** Kilobytes. Videos need far more headroom than images. */
    private const MAX_IMAGE_KB = 2048;

    private const MAX_VIDEO_KB = 20480;

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $data = [
            'sliders' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'layouts' => config('slider_layouts'),
            'search' => $request->string('q')->toString(),
            'layout' => $request->string('layout')->toString(),
            'status' => $request->string('status')->toString(),
            'sort' => $request->string('sort')->toString() ?: 'order',
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => [
                'total' => Slider::count(),
                'active' => Slider::where('is_active', true)->count(),
                'inactive' => Slider::where('is_active', false)->count(),
                'layouts' => Slider::distinct('layout')->count('layout'),
            ],
            /*
             | How many slides the landing page's hero will actually draw.
             |
             | The carousel renders only when main_banner has an active slide,
             | and when it has none the whole section - and the script that
             | runs it - is left out of the page entirely. That is the right
             | behaviour and completely invisible from here: this screen would
             | happily show three main_banner slides, all switched off, beside
             | a landing page with no banner and no explanation.
             |
             | So the count is carried to the view, which says so plainly.
             */
            'bannerLive' => Slider::where('layout', 'main_banner')
                ->where('is_active', true)
                ->count(),
            'bannerTotal' => Slider::where('layout', 'main_banner')->count(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.sliders._list', $data)
            : view('admin.sliders.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return Slider::query()
            ->search($request->string('q')->toString())
            ->when($request->string('layout')->toString(), function (Builder $query, string $layout) {
                $query->where('layout', $layout);
            })
            ->when($request->string('status')->toString(), function (Builder $query, string $status) {
                $query->where('is_active', $status === 'active');
            })
            ->tap(function (Builder $query) use ($request) {
                match ($request->string('sort')->toString()) {
                    'title_asc' => $query->orderBy('title'),
                    'title_desc' => $query->orderByDesc('title'),
                    'newest' => $query->latest(),
                    'oldest' => $query->oldest(),
                    default => $query->orderBy('layout')->orderBy('item_no'),
                };
            });
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.sliders._form', [
            'slider' => new Slider(),
            'layouts' => config('slider_layouts'),
        ]);
    }

    public function edit(Slider $slider): View
    {
        return view('admin.sliders._form', [
            'slider' => $slider,
            'layouts' => config('slider_layouts'),
        ]);
    }

    public function show(Slider $slider): View
    {
        return view('admin.sliders._show', ['slider' => $slider]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $slider = new Slider([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'layout' => $data['layout'],
            // Blank lands the slide at the end of its layout rather than
            // colliding with whatever is already at position 1.
            'item_no' => filled($data['item_no'] ?? null)
                ? (int) $data['item_no']
                : Slider::nextItemNo($data['layout']),
            'redirect_url' => $data['redirect_url'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        foreach (Slider::MEDIA as $column => $slot) {
            if ($request->hasFile($slot['field'])) {
                $slider->{$column} = $this->storeMedia($request->file($slot['field']));
            }
        }

        $slider->save();

        ActivityLog::record('slider.created', "Created slider \"{$slider->title}\"", $slider);

        return ApiResponse::success("Slider \"{$slider->title}\" created.", $this->payload($slider));
    }

    public function update(Request $request, Slider $slider): JsonResponse
    {
        $data = $this->validated($request);

        $slider->fill([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'layout' => $data['layout'],
            'item_no' => (int) ($data['item_no'] ?? $slider->item_no),
            'redirect_url' => $data['redirect_url'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        foreach (Slider::MEDIA as $column => $slot) {
            if (! $request->hasFile($slot['field'])) {
                continue;
            }

            $previous = $slider->{$column};
            $slider->{$column} = $this->storeMedia($request->file($slot['field']));

            // Dropped only once the replacement is on disk, so a failed
            // write cannot leave the slot pointing at nothing.
            if (filled($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        $slider->save();

        ActivityLog::record('slider.updated', "Updated slider \"{$slider->title}\"", $slider);

        return ApiResponse::success("Slider \"{$slider->title}\" updated.", $this->payload($slider));
    }

    public function destroy(Slider $slider): JsonResponse
    {
        $title = $slider->title;
        $files = array_filter(array_map(fn ($c) => $slider->{$c}, array_keys(Slider::MEDIA)));

        $slider->delete();

        // All four slots, so nothing is left orphaned on disk.
        foreach ($files as $path) {
            Storage::disk('public')->delete($path);
        }

        ActivityLog::record('slider.deleted', "Deleted slider \"{$title}\"");

        return ApiResponse::success("Slider \"{$title}\" deleted.");
    }

    public function toggleStatus(Slider $slider): JsonResponse
    {
        $active = ! $slider->is_active;

        $slider->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $active ? 'slider.activated' : 'slider.deactivated',
            ($active ? 'Activated' : 'Deactivated')." slider \"{$slider->title}\"",
            $slider,
        );

        return ApiResponse::success(
            "\"{$slider->title}\" is now ".($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    /**
     * Copy a slide, media and all.
     *
     * The files are genuinely copied rather than shared: two rows pointing
     * at one path means deleting either slide breaks the other.
     */
    public function duplicate(Slider $slider): JsonResponse
    {
        $copy = $slider->replicate(array_keys(Slider::MEDIA));

        $copy->title = Str::limit($slider->title, 170, '').' (copy)';
        $copy->item_no = Slider::nextItemNo($slider->layout);
        // Inactive on purpose: a duplicate is a draft until someone says so.
        $copy->is_active = false;

        $disk = Storage::disk('public');

        foreach (array_keys(Slider::MEDIA) as $column) {
            $source = $slider->{$column};

            if (blank($source) || ! $disk->exists($source)) {
                continue;
            }

            $target = self::MEDIA_DIR.'/'.Str::random(40).'.'.pathinfo($source, PATHINFO_EXTENSION);
            $disk->copy($source, $target);

            $copy->{$column} = $target;
        }

        $copy->save();

        ActivityLog::record('slider.duplicated', "Duplicated slider \"{$slider->title}\"", $copy);

        return ApiResponse::success("Copied to \"{$copy->title}\".", $this->payload($copy));
    }

    /**
     * Clear one media slot without touching the rest of the slide.
     */
    public function destroyMedia(Slider $slider, string $slot): JsonResponse
    {
        $column = collect(Slider::MEDIA)
            ->search(fn (array $definition) => $definition['field'] === $slot);

        abort_if($column === false, 404);

        if (blank($slider->{$column})) {
            return ApiResponse::success('There was nothing to remove.', ['media' => $slider->mediaUrls()]);
        }

        $path = $slider->{$column};
        $slider->forceFill([$column => null])->save();
        Storage::disk('public')->delete($path);

        ActivityLog::record(
            'slider.media_removed',
            'Removed the '.Slider::MEDIA[$column]['label']." from \"{$slider->title}\"",
            $slider,
        );

        return ApiResponse::success(
            Slider::MEDIA[$column]['label'].' removed.',
            ['media' => $slider->fresh()->mediaUrls()],
        );
    }

    /* ------------------------------------------------------- validation */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'item_no' => ['nullable', 'integer', 'between:1,65535'],
            'layout' => ['required', Rule::in(array_keys(config('slider_layouts')))],
            'redirect_url' => ['nullable', 'url', 'max:500'],
            'is_active' => ['boolean'],
        ];

        $messages = ['layout.in' => 'Choose a layout from the list.'];

        foreach (Slider::MEDIA as $slot) {
            $rules[$slot['field']] = $slot['kind'] === 'image'
                ? [
                    'nullable',
                    'file',
                    // SVG is on the list the brief asked for, so `image` is
                    // not usable here - it rejects SVG outright.
                    'mimes:svg,png,jpg,jpeg',
                    'mimetypes:image/svg+xml,image/png,image/jpeg',
                    'max:'.self::MAX_IMAGE_KB,
                ]
                : [
                    'nullable',
                    'file',
                    'mimes:mp4,mov',
                    'mimetypes:video/mp4,video/quicktime',
                    'max:'.self::MAX_VIDEO_KB,
                ];

            $label = $slot['label'];
            $limit = $slot['kind'] === 'image' ? '2 MB' : '20 MB';

            $messages[$slot['field'].'.mimes'] = "{$label} must be a {$slot['formats']} file.";
            $messages[$slot['field'].'.mimetypes'] = "{$label} is not a {$slot['formats']} file.";
            $messages[$slot['field'].'.max'] = "{$label} must be {$limit} or smaller.";
        }

        $data = $request->validate($rules, $messages);


        /*
         | The per-slot pixel cap and the SVG script check. Shared with
         | Events, which accepts the same formats; see App\Support\ImageGuard.
         */
        $imageSlots = collect(Slider::MEDIA)
            ->filter(fn (array $slot) => $slot['kind'] === 'image')
            ->mapWithKeys(fn (array $slot) => [$slot['field'] => $slot])
            ->all();

        ImageGuard::dimensions($request, $imageSlots);
        ImageGuard::svgContents($request, $imageSlots);

        return $data;
    }

    private function storeMedia(UploadedFile $file): string
    {
        return $file->store(self::MEDIA_DIR, 'public');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Slider $slider): array
    {
        return [
            'id' => $slider->id,
            'title' => $slider->title,
            'layout' => $slider->layoutLabel(),
            'item_no' => $slider->item_no,
            'is_active' => $slider->is_active,
            'media' => $slider->mediaUrls(),
        ];
    }
}
