<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CashPaidOut;
use App\Models\CashRegister;
use App\Services\CashRegisterService;
use App\Services\Erp\ErpLink;
use App\Support\ApiResponse;
use App\Support\BusinessDay;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Khana\Kit\Support\Ulid;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cash register / day close.
 *
 * One session per shop per business day - see CashRegister's migration for
 * why. Opening declares the float; closing declares what was actually
 * counted and freezes the expected figure to compare it against; approving
 * is the manager's sign-off, variance and all.
 *
 * In between, cash leaves the drawer for things that are not refunds - the
 * gas man, a supplier at the back door - and is paid out here, on the open
 * register's own page, so the expected figure and the reason it moved are
 * on one screen.
 */
class CashRegisterController extends Controller
{
    private const PAGE_SIZES = [10, 15, 25, 50];

    public function __construct(
        private readonly CashRegisterService $registers,
        private readonly ErpLink $erp,
    ) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 15);
        $perPage = in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 15;

        $rows = $this->filtered($request)
            ->with(['shop:id,name', 'openedBy:id,name', 'closedBy:id,name'])
            ->paginate($perPage)
            ->withQueryString();

        $shop = CurrentShop::get();
        $today = $shop ? CashRegister::forShop($shop->id)->whereDate('business_date', today())->first() : null;

        /*
         | An earlier day somebody opened and never counted.
         |
         | It blocks today's register - see CashRegisterService::open() - so
         | the screen has to name it rather than leave a cashier pressing a
         | button that refuses. It is also the only prompt that day will ever
         | get: nothing else in the system goes looking for a register that
         | was never closed.
         */
        $stale = $shop
            ? CashRegister::forShop($shop->id)
                ->where('status', CashRegister::OPEN)
                ->whereDate('business_date', '<', today())
                ->orderBy('business_date')
                ->first()
            : null;

        $data = [
            'registers' => $rows,
            'status' => $request->string('status')->toString(),
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
            'statuses' => CashRegister::STATUSES,
            'showsShop' => CurrentShop::id() === null,
            'shop' => $shop,
            'today' => $today,
            'stale' => $stale,
        ];

        return $request->header('X-Fragment')
            ? view('admin.registers._list', $data)
            : view('admin.registers.index', $data);
    }

    private function filtered(Request $request): Builder
    {
        return CashRegister::query()
            ->ofStatus($request->string('status')->toString())
            ->between($request->string('from')->toString(), $request->string('to')->toString())
            ->orderByDesc('business_date')
            ->orderByDesc('id');
    }

    public function show(CashRegister $register): View
    {
        $register->load(['shop', 'openedBy', 'closedBy', 'approvedBy']);

        $paidOuts = $this->registers->paidOuts($register);

        return view('admin.registers.show', [
            'register' => $register,
            'expectedSoFar' => $register->isOpen() ? $this->registers->expectedCash($register) : null,
            /*
             | What left the drawer that day, under the figure it explains.
             | Shown on a closed day too: it is the first thing a manager
             | signing off a short till wants to read.
             */
            'paidOuts' => $paidOuts,
            'paidOutTotal' => (float) $paidOuts->sum('amount'),
            'takesPaidOuts' => $this->registers->takesPaidOuts($register),
            'categories' => CashPaidOut::CATEGORIES,
            /*
             | Whether the paid-out card offers ERP's unpaid supplier bills.
             | The bills are not read here: the card asks for them once the
             | page is up (ErpPanelController::bills), so ERP being slow or
             | down costs the cashier a line of that card and nothing else.
             */
            'erpLinked' => $this->erp->linked($register->shop),
            // Stored instants are UTC; a till reads its own clock.
            'zone' => BusinessDay::zone($register->shop),
        ]);
    }

    /* ------------------------------------------------------------ writes */

    public function store(Request $request): JsonResponse
    {
        $shop = CurrentShop::get();

        if ($shop === null) {
            return ApiResponse::error('Choose a single shop before opening its register.');
        }

        $data = $request->validate([
            'opening_float' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $register = $this->registers->open($shop, (float) $data['opening_float']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Register opened for {$register->business_date->format('d M Y')}.",
            ['id' => $register->id],
            route('admin.registers.show', $register),
        );
    }

    public function close(Request $request, CashRegister $register): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->registers->close($register, (float) $data['counted_cash'], $data['notes'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        $register = $register->fresh();
        $variance = (float) $register->variance;

        $message = abs($variance) <= 0.004
            ? "Register closed for {$register->business_date->format('d M Y')}. The till balances."
            : sprintf(
                'Register closed for %s. %s by ₹%s.',
                $register->business_date->format('d M Y'),
                $variance > 0 ? 'Over' : 'Short',
                number_format(abs($variance), 2),
            );

        return ApiResponse::success($message, [], route('admin.registers.show', $register));
    }

    /**
     * Pay cash out of the open drawer.
     *
     * Nothing here calls ERP, on purpose. The bill a paid-out names was
     * picked from a list ERP supplied when the card was drawn, and comes
     * back as its id with the supplier and bill number as they read there;
     * they are recorded as given and ERP matches the id when the event
     * reaches it. Checking it here would mean a till that cannot record
     * cash leaving the drawer because another service is slow.
     */
    public function payOut(Request $request, CashRegister $register): JsonResponse
    {
        if (! $this->registers->takesPaidOuts($register)) {
            return ApiResponse::error(
                $register->isOpen()
                    ? "This register is for {$register->business_date->format('d M Y')}. Close it and open today's to pay cash out."
                    : 'That register is no longer open, so nothing can be paid out of it.'
            );
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'between:0.01,99999999.99'],
            'category' => ['required', 'string', Rule::in(array_keys(CashPaidOut::CATEGORIES))],
            // A bill says what the cash was for; anything else needs a word.
            'note' => ['nullable', 'required_without:supplier_bill_id', 'string', 'max:190'],
            'supplier_bill_id' => ['nullable', 'string', 'regex:'.Ulid::PATTERN],
            'supplier_name' => ['nullable', 'string', 'max:190'],
            'supplier_bill_no' => ['nullable', 'string', 'max:100'],
        ], [
            'amount.between' => 'Enter how much cash is leaving the drawer.',
            'category.in' => 'Choose what the cash was for from the list.',
            'note.required_without' => 'Say what the cash was for - the day close will ask.',
            'supplier_bill_id.regex' => 'That is not a supplier bill from the list. Pick the bill again.',
        ]);

        $billId = $data['supplier_bill_id'] ?? null;

        // Bills are ERP's, and only an outlet linked to ERP is ever shown
        // any. One arriving for another outlet did not come from this screen.
        if ($billId !== null && ! $this->erp->linked($register->shop)) {
            return ApiResponse::error('This outlet has no supplier bills to pay from here.');
        }

        try {
            $paidOut = $this->registers->payOut(
                $register->shop,
                (float) $data['amount'],
                (string) ($data['note'] ?? ''),
                $data['category'],
                supplierBillId: $billId,
                supplierName: $data['supplier_name'] ?? null,
                supplierBillNo: $data['supplier_bill_no'] ?? null,
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            sprintf('₹%s paid out of the drawer.', number_format((float) $paidOut->amount, 2)),
            ['id' => $paidOut->id],
            route('admin.registers.show', $register),
        );
    }

    public function approve(Request $request, CashRegister $register): JsonResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->registers->approve($register, $data['review_note'] ?? null);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        return ApiResponse::success(
            "Register for {$register->business_date->format('d M Y')} approved.",
            [],
            route('admin.registers.show', $register),
        );
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filename = 'cash-registers-'.now()->format('Y-m-d-His').'.csv';

        $columns = ['Date', 'Shop', 'Opening Float', 'Expected', 'Counted', 'Variance', 'Status'];

        $query = $this->filtered($request)->with('shop');

        return response()->streamDownload(function () use ($query, $columns) {
            $handle = fopen('php://output', 'wb');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $columns);

            $query->chunkById(300, function ($chunk) use ($handle) {
                foreach ($chunk as $register) {
                    fputcsv($handle, [
                        $register->business_date->format('Y-m-d'),
                        $register->shop?->name,
                        number_format((float) $register->opening_float, 2, '.', ''),
                        $register->expected_cash !== null ? number_format((float) $register->expected_cash, 2, '.', '') : '',
                        $register->counted_cash !== null ? number_format((float) $register->counted_cash, 2, '.', '') : '',
                        $register->variance !== null ? number_format((float) $register->variance, 2, '.', '') : '',
                        $register->statusLabel(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
