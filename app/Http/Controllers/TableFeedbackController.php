<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Setting;
use App\Models\TableSession;
use App\Services\TableSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The guest saying how it was (§15).
 *
 * ---------------------------------------------------------------------------
 * One tap, and nothing is required
 * ---------------------------------------------------------------------------
 *
 * A rating and nothing else. No name, no email, no account, no "tell us
 * more". Every field asked for is a person who leaves without answering, and
 * the people who give up first are exactly the ones whose evening went badly.
 *
 * ---------------------------------------------------------------------------
 * It works after the bill
 * ---------------------------------------------------------------------------
 *
 * Deliberately still reachable once the sitting is billed or closed, which is
 * when most people actually rate a meal - on the pavement outside. The sitting
 * comes from the guest's own cookie, and a closed one is still theirs.
 */
class TableFeedbackController extends Controller
{
    public function __construct(private readonly TableSessionService $sessions) {}

    public function show(Request $request): View|RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return redirect()->route('table.expired');
        }

        return view('table.feedback', [
            'session' => $session,
            'table' => $session->table,
            'shop' => $session->table?->shop,
            'company' => Setting::get('company_name', config('app.name')),
            'existing' => Feedback::allShops()
                ->where('table_session_id', $session->id)
                ->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $session = $this->session($request);

        if ($session === null) {
            return redirect()->route('table.expired');
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'food_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'service_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        /*
         | Updated rather than added, so a phone left on a table cannot leave
         | forty one-star ratings and a guest who changes their mind is not
         | counted twice. The unique index on the session enforces the same
         | thing at the bottom.
         */
        $feedback = Feedback::allShops()->updateOrCreate(
            ['table_session_id' => $session->id],
            [
                'shop_id' => $session->shop_id,
                'order_id' => $session->orders()->latest('id')->value('id'),
                'customer_id' => $session->customer_id,
                'rating' => $data['rating'],
                'food_rating' => $data['food_rating'] ?? null,
                'service_rating' => $data['service_rating'] ?? null,
                'comment' => $data['comment'] ?? null,
                'guest_name' => $session->guest_name,
                'guest_mobile' => $session->guest_mobile,
            ],
        );

        // One alert per sitting: a guest who changes their rating updates it
        // rather than ringing the bell again (the dedupe key is the row).
        app(\App\Services\EventNotifier::class)->feedbackReceived($feedback);

        return redirect()
            ->route('table.feedback')
            ->with('table_notice', (int) $data['rating'] >= 4
                ? 'Thank you — glad it was good.'
                : 'Thank you for telling us. Somebody will read this.');
    }

    /**
     * The sitting, from the guest's own cookie.
     *
     * Unlike the cart and the order, a closed sitting is fine here: most
     * people rate a meal after they have paid for it.
     */
    private function session(Request $request): ?TableSession
    {
        $token = $request->session()->get(TableScanController::SESSION_KEY);

        if (blank($token)) {
            return null;
        }

        return $this->sessions->resolve($token)
            ?? TableSession::allShops()->where('token', $token)->first();
    }
}
