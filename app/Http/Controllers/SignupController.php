<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\UserSession;
use App\Services\SignupService;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * A restaurant opening its own account (§21).
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 *
 * The landing page sells a plan and then, until now, asked the visitor to book
 * a demo and wait for somebody to build their account by hand. Everything that
 * account is made of - a company, a branch, an owner's login, a subscription -
 * already existed as an administrator's screen; none of it was reachable by
 * the person actually buying.
 *
 * So this is the same four rows, written by the customer instead. What it does
 * not do is invent a second way of making them: the writing is
 * App\Services\SignupService, which uses the same models, the same slug and
 * plan rules, and the same SubscriptionService as the back office.
 *
 * ---------------------------------------------------------------------------
 * It is public, so it is defended like the demo form
 * ---------------------------------------------------------------------------
 *
 * Three layers, cheapest to the honest visitor first: a honeypot field, a rate
 * limit on the route, and a password rule strong enough that the account it
 * opens is worth having. Deliberately no CAPTCHA, for the reason
 * DemoRequestController gives - this form is how the business gets paid.
 *
 * ---------------------------------------------------------------------------
 * JavaScript is an improvement, never a requirement
 * ---------------------------------------------------------------------------
 *
 * With a script, the page posts through fetch and answers with a toast - which
 * keeps what the visitor typed on screen while the account is being made, and
 * puts a field error beside the field rather than at the top of a reloaded
 * page. Without one, the browser posts the form and follows the redirect, and
 * the result is identical.
 *
 * Both paths run the same validation and the same service; this controller
 * only chooses how to answer. A signup form that silently needs a script is a
 * signup form that silently loses the owner filling it in on a phone with a
 * bad connection.
 */
class SignupController extends Controller
{
    /** The hidden field. Named like something a bot would want to fill. */
    private const HONEYPOT = 'website';

    public function __construct(private readonly SignupService $signups) {}

    /* --------------------------------------------------------------- form */

    public function create(Request $request, ?string $plan = null): View|RedirectResponse
    {
        $plans = Plan::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('monthly_price')
            ->get();

        abort_if($plans->isEmpty(), 404, 'There is nothing to sign up to yet.');

        /*
         | One address per plan.
         |
         | `/signup?plan=restaurant` was the old shape and is still out there
         | in links, so it is answered rather than broken - by sending the
         | browser to the path form once. Left as two working URLs it would
         | be the same page under two addresses, which is a split ranking and
         | two versions of every link anybody shares.
         */
        if ($plan === null && $request->filled('plan')) {
            return redirect()->route('signup', array_filter([
                'plan' => $request->string('plan')->toString(),
                'period' => $request->string('period')->toString() ?: null,
            ]), 301);
        }

        /*
         | A slug that names nothing - an old link, a typed URL, a plan since
         | withdrawn - falls back to the cheapest rather than erroring:
         | somebody who wanted to sign up should land on a form, not on a 404
         | about a plan code.
         */
        $chosen = $plans->firstWhere('slug', $plan) ?? $plans->first();

        $period = $request->string('period')->toString() === Plan::YEARLY
            ? Plan::YEARLY
            : Plan::MONTHLY;

        return view('signup.plan', [
            'plans' => $plans,
            'chosen' => $chosen,
            'period' => $period,
            'site' => $this->site(),
            'modules' => Modules::catalogue(),
        ]);
    }

    /**
     * Step two: who you are and what the restaurant is called.
     *
     * Its own page, and that is the point. Asking for a business name, a city,
     * an email and a password underneath a price list is a long form on a
     * phone, and the plan - the one decision a visitor has actually made by
     * the time they arrive - scrolls out of sight while they fill it in.
     *
     * Here the plan is settled, stated at the top with a link back, and the
     * only thing on screen is what is still being asked for.
     *
     * A plan slug that names nothing sends them back to step one rather than
     * erroring: there is no sense collecting details against a plan that
     * cannot be bought.
     */
    public function details(Request $request, string $plan): View|RedirectResponse
    {
        $chosen = Plan::query()->active()->where('slug', $plan)->first();

        if ($chosen === null) {
            return redirect()->route('signup');
        }

        $period = $request->string('period')->toString() === Plan::YEARLY
            ? Plan::YEARLY
            : Plan::MONTHLY;

        return view('signup.details', [
            'chosen' => $chosen,
            'period' => $period,
            'site' => $this->site(),
        ]);
    }

    /**
     * The chrome both steps draw.
     *
     * @return array{name: string, logo: string|null}
     */
    private function site(): array
    {
        return [
            'name' => Setting::get('company_name') ?: config('app.name'),
            'logo' => $this->logoUrl(),
        ];
    }

    /**
     * The platform's own logo, when there is a file behind the setting.
     *
     * Same rule as the landing page's: a path whose file has been swept off
     * disk renders as a broken image, and the wordmark beside it reads
     * perfectly well on its own.
     */
    private function logoUrl(): ?string
    {
        $path = Setting::get('site_logo');

        if (blank($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($path) ? $disk->url($path) : null;
    }

    /* -------------------------------------------------------------- write */

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /*
         | Caught in the honeypot. Accepted, nothing written, and sent back to
         | the form rather than told why - telling a bot it failed only
         | teaches whoever wrote it to leave the field alone next time.
         */
        if (filled($request->input(self::HONEYPOT))) {
            return $request->expectsJson()
                ? json_success('Thank you.', [], route('signup'))
                : redirect()->route('signup');
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],

            /*
             | Optional, and it is the one field on this form that most
             | visitors should leave alone: a single restaurant's outlet is
             | the business. SignupService falls back to the business name.
             */
            'outlet_name' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:90'],

            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:30'],

            /*
             | Set here and never emailed. The welcome-email flow exists for
             | accounts an administrator opens on somebody else's behalf; a
             | person sitting at the form is already present, and a link they
             | have to go and find in a spam folder is where signups are lost.
             */
            'password' => ['required', 'confirmed', Password::min(8)],

            'plan' => ['required', 'string', Rule::exists('plans', 'slug')->where('is_active', true)],
            'period' => ['required', Rule::in(Plan::PERIODS)],

            'terms' => ['accepted'],
        ], [
            'email.unique' => 'That email already has an account. Sign in instead, or use another address.',
            'terms.accepted' => 'Please accept the terms to open an account.',
            'plan.exists' => 'That plan is no longer available. Choose another.',
        ]);

        $plan = Plan::query()->active()->where('slug', $data['plan'])->firstOrFail();

        $created = $this->signups->register($data, $plan, $data['period']);

        $owner = $created['owner'];
        $shop = $created['shop'];
        $subscription = $created['subscription'];

        /*
         | Signed straight in.
         |
         | The alternative - "your account is ready, now please log in" -
         | asks somebody who typed their password forty seconds ago to type
         | it again, and is the last screen a fair number of them ever see.
         |
         | Everything LoginController does on a normal sign-in is done here
         | too, in the same order and for the same reasons: a fresh session
         | id first so a pre-signup session cannot be replayed, then the
         | device row, which has to carry the id the browser will actually
         | present or a remote logout cannot close it.
         */
        Auth::guard('web')->login($owner);

        $request->session()->regenerate();

        UserSession::start($owner, $request);

        LoginHistory::record(LoginHistory::SUCCESS, $owner->email, $owner, null, $request);

        $owner->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
            'login_count' => 1,
        ])->saveQuietly();

        ActivityLog::record(
            'signup.completed',
            sprintf('Opened an account for "%s" on the %s plan', $shop->name, $plan->name),
            $shop,
            ['plan' => $plan->code, 'period' => $data['period']],
        );

        /*
         | Where they land depends on what they bought, and both answers are
         | the honest one for their account:
         |
         |   a trial      straight into the dashboard. They have a fortnight
         |                to decide, and a payment screen in front of a
         |                product they have not seen yet sells nothing.
         |   no trial     billing. The term this plan opened has already
         |                ended - see SignupService - so the outlet is locked
         |                until it is paid for, and being shown the lock
         |                before the bill would be a puzzle rather than a
         |                checkout.
         */
        if ($subscription->isUsable()) {
            $message = sprintf(
                'Welcome, %s. "%s" is on the %s plan%s.',
                $owner->name,
                $shop->name,
                $plan->name,
                $subscription->onTrial()
                    ? ', free until '.$subscription->trial_ends_at->format('j M Y')
                    : '',
            );

            return $this->done($request, $message, route('admin.dashboard'));
        }

        return $this->done($request, sprintf(
            '"%s" is ready. Pay for the %s plan to open it.',
            $shop->name,
            $plan->name,
        ), route('admin.billing.show'));
    }

    /**
     * Answer whichever way the form asked.
     *
     * The page posts through fetch when JavaScript is available, so it can
     * show a toast instead of a full reload; without it the browser posts
     * the form itself and follows a redirect, exactly as before. The account
     * is created identically either way - this only chooses how to say so.
     *
     * Both carry the same sentence, because a message that only appears in
     * one of the two paths is a message nobody maintains.
     */
    private function done(Request $request, string $message, string $target): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return json_success($message, [], $target);
        }

        return redirect()->to($target)->with('status', $message);
    }
}
