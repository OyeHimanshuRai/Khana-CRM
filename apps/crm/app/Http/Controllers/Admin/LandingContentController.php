<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The CRUD that five landing-page modules have in common (§19).
 *
 * ---------------------------------------------------------------------------
 * Why this is a base class, when nothing else here is
 * ---------------------------------------------------------------------------
 *
 * Every other content module in this panel - Faq, Service, Slider, Event - is
 * a standalone controller, and that is the right shape for them: they arrived
 * one at a time and each grew its own behaviour.
 *
 * These five did not. Testimonials, outlet types, integrations, screenshots
 * and statistics shipped together, for one page, and they are administered
 * identically: a sortable list, an active toggle, a modal form, one optional
 * image. Written out five times that is roughly a thousand lines in which the
 * only differences are a model name and a validation array - and the real cost
 * is not the typing, it is that a fix to the image-delete path would then need
 * applying five times and would get applied to four.
 *
 * So the shared half lives here and each module declares only what makes it
 * itself. A module that later grows its own behaviour simply overrides the
 * method; nothing here is final.
 *
 * ---------------------------------------------------------------------------
 * What a subclass must supply
 * ---------------------------------------------------------------------------
 *
 *   model()      the Eloquent class
 *   views()      the view namespace, e.g. 'admin.testimonials'
 *   rules()      validation, which is also the list of writable fields
 *   noun()       what to call one of them in a message and an audit line
 *   titleOf()    the one line that identifies a row in a log entry
 */
abstract class LandingContentController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50, 100];

    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function views(): string;

    /** @return array<string, mixed> */
    abstract protected function rules(): array;

    /** Singular, lower case: "testimonial", "outlet type". */
    abstract protected function noun(): string;

    abstract protected function titleOf(Model $row): string;

    /**
     * Where uploads go, or null for a module with no image.
     *
     * Null is the signal the whole image path keys off: no upload is accepted,
     * no file is deleted, and the "remove image" route 404s rather than
     * pretending.
     */
    protected function imageDir(): ?string
    {
        return null;
    }

    /**
     * Anything else the list and form views need - a category list, an icon
     * set. Merged into both.
     *
     * @return array<string, mixed>
     */
    protected function extra(): array
    {
        return [];
    }

    /** The audit-log prefix: "testimonial.created". */
    protected function logKey(): string
    {
        return str_replace(' ', '_', $this->noun());
    }

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $model = $this->model();

        $data = [
            // Named `rows` for every module, so one shared pagination and
            // empty-state partial serves all five.
            'rows' => $this->filtered($request)->paginate($perPage)->withQueryString(),
            'search' => $request->string('q')->toString(),
            'status' => $request->string('status')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'noun' => $this->noun(),
            'stats' => [
                'total' => $model::query()->count(),
                'active' => $model::query()->where('is_active', true)->count(),
                'inactive' => $model::query()->where('is_active', false)->count(),
            ],
            ...$this->extra(),
        ];

        return $request->header('X-Fragment')
            ? view($this->views().'._list', $data)
            : view($this->views().'.index', $data);
    }

    /** @return Builder<Model> */
    protected function filtered(Request $request): Builder
    {
        $model = $this->model();

        return $model::query()
            ->search($request->string('q')->toString())
            ->when(
                $request->string('status')->toString(),
                fn (Builder $q, string $status) => $q->where('is_active', $status === 'active'),
            )
            ->ordered();
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        $model = $this->model();

        return view($this->views().'._form', ['row' => new $model(), ...$this->extra()]);
    }

    public function edit(int $id): View
    {
        return view($this->views().'._form', ['row' => $this->find($id), ...$this->extra()]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $model = $this->model();

        /** @var Model $row */
        $row = new $model();
        $row->fill($this->fillable($data));
        $row->is_active = (bool) ($data['is_active'] ?? true);

        $this->attachImage($request, $row);

        $row->save();

        ActivityLog::record(
            $this->logKey().'.created',
            'Created a '.$this->noun(),
            $row,
            ['title' => $this->titleOf($row)],
        );

        return ApiResponse::success(ucfirst($this->noun()).' created.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules());

        $row = $this->find($id);
        $row->fill($this->fillable($data));
        // Absent means unticked: a checkbox that is off is not submitted.
        $row->is_active = (bool) ($data['is_active'] ?? false);

        $this->attachImage($request, $row, replacing: true);

        $row->save();

        ActivityLog::record(
            $this->logKey().'.updated',
            'Updated a '.$this->noun(),
            $row,
            ['title' => $this->titleOf($row)],
        );

        return ApiResponse::success(ucfirst($this->noun()).' updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        $row = $this->find($id);
        $title = $this->titleOf($row);

        $image = $this->imageDir() ? ($row->image_path ?? null) : null;

        $row->delete();

        // After the delete, not before: a failed delete must not leave the
        // row pointing at a file that is already gone.
        if (filled($image)) {
            Storage::disk('public')->delete($image);
        }

        ActivityLog::record(
            $this->logKey().'.deleted',
            'Deleted a '.$this->noun(),
            null,
            ['title' => $title],
        );

        return ApiResponse::success(ucfirst($this->noun()).' deleted.');
    }

    /**
     * Switch a row off without deleting it.
     *
     * The distinction matters on a marketing page: a testimonial from a
     * customer who has since left is not a mistake to erase, it is something
     * to stop showing.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $row = $this->find($id);
        $active = ! $row->is_active;

        $row->forceFill(['is_active' => $active])->save();

        ActivityLog::record(
            $this->logKey().($active ? '.activated' : '.deactivated'),
            ($active ? 'Activated' : 'Deactivated').' a '.$this->noun(),
            $row,
            ['title' => $this->titleOf($row)],
        );

        return ApiResponse::success(
            ucfirst($this->noun()).' is now '.($active ? 'active' : 'inactive').'.',
            ['is_active' => $active],
        );
    }

    public function destroyImage(int $id): JsonResponse
    {
        abort_if($this->imageDir() === null, 404);

        $row = $this->find($id);

        if (blank($row->image_path)) {
            return ApiResponse::success('There was no image to remove.', ['image' => null]);
        }

        $path = $row->image_path;
        $row->forceFill(['image_path' => null])->save();

        Storage::disk('public')->delete($path);

        ActivityLog::record($this->logKey().'.image_removed', 'Removed an image', $row);

        return ApiResponse::success('Image removed.', ['image' => null]);
    }

    /* ---------------------------------------------------------- internals */

    protected function find(int $id): Model
    {
        $model = $this->model();

        return $model::query()->findOrFail($id);
    }

    /**
     * The validated data minus the fields the model must not mass-assign.
     *
     * `image` is a file and `is_active` is set explicitly above, because a
     * missing checkbox has to mean false rather than "leave it alone".
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillable(array $data): array
    {
        return array_diff_key($data, array_flip(['image', 'is_active']));
    }

    /**
     * Move an upload onto the row.
     *
     * The previous file is deleted only once the new one is stored, so a
     * failed upload leaves the old image in place rather than leaving the row
     * with nothing.
     */
    protected function attachImage(Request $request, Model $row, bool $replacing = false): void
    {
        $dir = $this->imageDir();

        if ($dir === null || ! $request->hasFile('image')) {
            return;
        }

        $previous = $replacing ? ($row->image_path ?? null) : null;

        $row->image_path = $request->file('image')->store($dir, 'public');

        if (filled($previous)) {
            Storage::disk('public')->delete($previous);
        }
    }

    /**
     * The image rules every module with an upload shares.
     *
     * `image` (the MIME check) plus an explicit extension list: the first
     * refuses a renamed executable, the second refuses an SVG, which is an
     * image the browser will happily run JavaScript out of.
     *
     * @return array<int, mixed>
     */
    protected function imageRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];
    }
}
