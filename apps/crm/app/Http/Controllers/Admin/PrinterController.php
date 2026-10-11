<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\KitchenStation;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\PrintService;
use App\Support\ApiResponse;
use App\Support\CurrentShop;
use App\Support\EscPos;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Printers, and the log of what came out of them (SRS 4, 6, 8, 21).
 *
 * Two screens in one place because they answer each other: the list says what
 * is configured, and the log says whether any of it is working. A printer
 * somebody set up in March and has never printed from is a much easier thing
 * to spot with both on the same page.
 */
class PrinterController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(private readonly PrintService $printing) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $printers = Printer::query()
            ->with('station:id,name')
            ->when($request->string('q')->toString(), function (Builder $query, string $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('host', 'like', $like));
            })
            ->when($request->string('kind')->toString(), fn (Builder $q, string $kind) => $q->where('kind', $kind))
            ->orderBy('kind')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $data = [
            'printers' => $printers,
            'search' => $request->string('q')->toString(),
            'kind' => $request->string('kind')->toString(),
            'kinds' => Printer::KINDS,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'stats' => $this->stats(),
        ];

        return $request->header('X-Fragment')
            ? view('admin.printers._list', $data)
            : view('admin.printers.index', $data);
    }

    /** The print log: what was printed, by whom, and what failed. */
    public function jobs(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 25);

        $jobs = PrintJob::query()
            ->with(['printer:id,name', 'station:id,name', 'user:id,name', 'printable'])
            ->when($request->boolean('reprints'), fn (Builder $q) => $q->reprints())
            ->when($request->boolean('failed'), fn (Builder $q) => $q->failed())
            ->latest('id')
            ->paginate(in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25)
            ->withQueryString();

        $data = [
            'jobs' => $jobs,
            'reprints' => $request->boolean('reprints'),
            'failed' => $request->boolean('failed'),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
        ];

        return $request->header('X-Fragment')
            ? view('admin.printers._jobs', $data)
            : view('admin.printers.jobs', $data);
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        return [
            'total' => Printer::query()->count(),
            'automatic' => Printer::query()->active()->where('driver', Printer::NETWORK)->whereNotNull('host')->count(),
            'failed' => PrintJob::query()->failed()->where('created_at', '>=', now()->subDay())->count(),
            'reprints' => PrintJob::query()->reprints()->where('created_at', '>=', now()->startOfDay())->count(),
        ];
    }

    /* ------------------------------------------------------ modal screens */

    public function create(): View
    {
        return view('admin.printers._form', [
            'printer' => new Printer([
                'kind' => Printer::KOT,
                'driver' => Printer::BROWSER,
                'port' => 9100,
                'columns' => 42,
                'copies' => 1,
                'auto_cut' => true,
                'is_active' => true,
            ]),
            'stations' => $this->stations(),
        ]);
    }

    public function edit(Printer $printer): View
    {
        return view('admin.printers._form', [
            'printer' => $printer,
            'stations' => $this->stations(),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $printer = Printer::create($data);

        $this->keepOneDefault($printer);

        ActivityLog::record('printer.created', "Added printer \"{$printer->name}\"", $printer);

        return ApiResponse::success("Printer \"{$printer->name}\" added.");
    }

    public function update(Request $request, Printer $printer): JsonResponse
    {
        $data = $this->validated($request, $printer);

        $printer->fill($data)->save();

        $this->keepOneDefault($printer);

        ActivityLog::record('printer.updated', "Updated printer \"{$printer->name}\"", $printer);

        return ApiResponse::success("Printer \"{$printer->name}\" updated.");
    }

    public function destroy(Printer $printer): JsonResponse
    {
        $name = $printer->name;
        $printer->delete();

        ActivityLog::record('printer.deleted', "Removed printer \"{$name}\"", $printer);

        return ApiResponse::success("Printer \"{$name}\" removed.");
    }

    /**
     * Send a test page.
     *
     * The single most useful button on this screen. "Is the IP right, is it
     * switched on, is it on the same network" is three questions that a
     * strip of paper answers at once - and the alternative is finding out
     * during service.
     */
    public function test(Printer $printer): JsonResponse
    {
        if (! $printer->isAutomatic()) {
            return ApiResponse::error(
                "\"{$printer->name}\" prints through the browser, so there is nothing to test from here — "
                .'open any print screen and use the browser\'s own dialog.'
            );
        }

        try {
            $paper = (new EscPos($printer->columns))
                ->big('TEST')
                ->centre($printer->name)
                ->centre(CurrentShop::get()?->name ?? config('app.name'))
                ->rule()
                ->columns('Address', $printer->addressLabel())
                ->columns('Columns', (string) $printer->columns)
                ->columns('Printed', now()->format('j M Y, g:i a'))
                ->rule()
                ->line('If this strip is readable and the columns line up, this printer is ready.')
                ->feed(2)
                ->cut($printer->auto_cut);

            $socket = @fsockopen($printer->host, $printer->port, $code, $message, 3);

            if ($socket === false) {
                return ApiResponse::error(sprintf(
                    'Could not reach %s — %s. Check it is switched on and on the same network as this server.',
                    $printer->addressLabel(),
                    $message ?: 'no answer',
                ));
            }

            fwrite($socket, $paper->bytes());
            fclose($socket);

            $printer->forceFill(['last_used_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error('The printer could not be reached: '.$e->getMessage());
        }

        return ApiResponse::success("A test page has been sent to \"{$printer->name}\".");
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * One default per kind.
     *
     * Two printers both claiming to be the default for KOTs is a coin toss
     * every time a ticket is routed, and the sort of thing that looks like an
     * intermittent hardware fault.
     */
    private function keepOneDefault(Printer $printer): void
    {
        if (! $printer->is_default) {
            return;
        }

        Printer::query()
            ->where('kind', $printer->kind)
            ->whereKeyNot($printer->id)
            ->update(['is_default' => false]);
    }

    /** @return \Illuminate\Support\Collection<int, KitchenStation> */
    private function stations()
    {
        return KitchenStation::query()->orderBy('name')->get(['id', 'name', 'code']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Printer $printer = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:90'],
            'code' => [
                'required', 'string', 'max:40', 'alpha_dash',
                Rule::unique('printers', 'code')
                    ->where('shop_id', CurrentShop::idForWrite())
                    ->ignore($printer?->id)
                    ->withoutTrashed(),
            ],
            'kind' => ['required', Rule::in(array_keys(Printer::KINDS))],
            'driver' => ['required', Rule::in(array_keys(Printer::DRIVERS))],

            'kitchen_station_id' => ['nullable', 'integer', 'exists:kitchen_stations,id'],

            /*
             | Required only for a network printer, and the rule says so
             | rather than the form: a browser printer with a host typed into
             | it is somebody's half-finished thought, and a network printer
             | without one is a ticket that will never arrive.
             */
            'host' => ['nullable', 'string', 'max:120', 'required_if:driver,'.Printer::NETWORK],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],

            'columns' => ['required', 'integer', 'min:20', 'max:96'],
            'copies' => ['required', 'integer', 'min:1', 'max:5'],

            'auto_cut' => ['boolean'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:190'],
        ]);

        $data['port'] = (int) ($data['port'] ?? 9100);
        $data['auto_cut'] = $request->boolean('auto_cut');
        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active');

        // A station only means something for a kitchen ticket; carrying one
        // on a bill printer would make the routing read as if it mattered.
        if ($data['kind'] !== Printer::KOT) {
            $data['kitchen_station_id'] = null;
        }

        return $data;
    }
}
