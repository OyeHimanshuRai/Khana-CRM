<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Services\TableQrService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\QrCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The QR codes customers scan (§3, §4).
 *
 * The screen is a sheet of stickers, not a data table, because that is what
 * it is for: the operator prints it, cuts it up and puts one on each table.
 * The list view exists underneath for the cases the sheet cannot answer -
 * which table has no code, which one was regenerated last week and why.
 */
class TableQrController extends Controller
{
    /** Sticker sizes the print sheet offers, in millimetres. */
    private const SIZES = [
        'small' => ['label' => 'Small — 45mm', 'qr' => 110, 'card' => 45],
        'medium' => ['label' => 'Medium — 60mm', 'qr' => 150, 'card' => 60],
        'large' => ['label' => 'Large — 85mm', 'qr' => 210, 'card' => 85],
    ];

    public function __construct(private readonly TableQrService $qrs) {}

    public function index(Request $request): View
    {
        $tables = $this->filtered($request)
            ->with(['floor:id,name,code', 'activeQr'])
            ->get();

        $data = [
            'tables' => $tables,
            'floors' => Floor::active()->orderBy('sort_order')->orderBy('name')->get(),
            'floorId' => $request->integer('floor_id') ?: null,
            'search' => $request->string('q')->toString(),
            'stats' => [
                'tables' => RestaurantTable::query()->active()->count(),
                'coded' => RestaurantTable::query()->active()
                    ->whereHas('qrs', fn (Builder $q) => $q->whereNull('revoked_at'))
                    ->count(),
                'missing' => RestaurantTable::query()->active()
                    ->whereDoesntHave('qrs', fn (Builder $q) => $q->whereNull('revoked_at'))
                    ->count(),
                'scans' => (int) \App\Models\TableQr::query()->live()->sum('scan_count'),
            ],
        ];

        return $request->header('X-Fragment')
            ? view('admin.qr._list', $data)
            : view('admin.qr.index', $data);
    }

    /**
     * The printable sheet.
     *
     * Rendered as a full page rather than a fragment: it is sent to a
     * printer, and a fragment injected into the admin layout would carry the
     * sidebar onto the paper.
     *
     * Tables with no live code are left out rather than printed blank - a
     * sticker with an empty square on it is worse than a missing sticker,
     * because somebody will put it on a table.
     */
    public function sheet(Request $request): View
    {
        $size = $request->string('size')->toString();
        $size = array_key_exists($size, self::SIZES) ? $size : 'medium';

        $tables = $this->filtered($request)
            ->whereHas('qrs', fn (Builder $q) => $q->whereNull('revoked_at'))
            ->with(['floor:id,name,code', 'activeQr'])
            ->get();

        return view('admin.qr.sheet', [
            'tables' => $tables,
            'size' => $size,
            'sizes' => self::SIZES,
            'spec' => self::SIZES[$size],
            'shop' => CurrentShop::get(),
            'company' => Setting::get('company_name', config('app.name')),
            // Rendered once per table, here, so the view stays markup.
            'codes' => $tables->mapWithKeys(fn (RestaurantTable $table) => [
                $table->id => QrCode::inline($table->activeQr->url(), self::SIZES[$size]['qr']),
            ]),
        ]);
    }

    /** One code, big, for a screen or a single reprint. */
    public function show(RestaurantTable $table): View
    {
        $table->load(['floor', 'activeQr', 'qrs.issuer']);

        return view('admin.qr._show', [
            'table' => $table,
            'code' => $table->activeQr
                ? QrCode::inline($table->activeQr->url(), 260)
                : null,
        ]);
    }

    /* ------------------------------------------------------------ writes */

    /**
     * Give this table a new code and withdraw the old one.
     *
     * The destructive one on this screen: every sticker already on the table
     * stops working the moment this returns, which is exactly what it is for
     * when a code has been photographed and shared, and exactly what makes it
     * worth a confirmation in the UI.
     */
    public function regenerate(Request $request, RestaurantTable $table): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:250'],
        ]);

        $qr = $this->qrs->issue($table, $data['reason'] ?? null);

        return ApiResponse::success(
            "Table {$table->name} has a new QR code. Reprint and replace the sticker.",
            ['token' => $qr->token, 'url' => $qr->url()],
        );
    }

    /**
     * Withdraw a table's code without issuing another.
     *
     * The table can then take no QR orders at all until a code is issued
     * again, which is the point: a table out of service should not be
     * orderable from a photograph.
     */
    public function revoke(Request $request, RestaurantTable $table): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:250'],
        ]);

        $count = $this->qrs->revoke($table, $data['reason'] ?? null);

        if ($count === 0) {
            return ApiResponse::error("Table {$table->name} has no live QR code.");
        }

        return ApiResponse::success("The QR code for table {$table->name} has been withdrawn.");
    }

    /** Issue a code for every active table that has none. */
    public function issueMissing(): JsonResponse
    {
        $issued = $this->qrs->issueMissing();

        if ($issued->isEmpty()) {
            return ApiResponse::success('Every active table already has a QR code.');
        }

        return ApiResponse::success(sprintf(
            'Issued %d QR code%s. Print the sheet and put them out.',
            $issued->count(),
            $issued->count() === 1 ? '' : 's',
        ), ['issued' => $issued->count()]);
    }

    /* ----------------------------------------------------------- helpers */

    private function filtered(Request $request): Builder
    {
        return RestaurantTable::query()
            ->active()
            ->search($request->string('q')->toString())
            ->when($request->integer('floor_id'), fn (Builder $q, int $id) => $q->where('floor_id', $id))
            ->orderBy('floor_id')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
