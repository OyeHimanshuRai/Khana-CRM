<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LoginHistory;
use App\Models\Page;
use App\Models\Plan;
use App\Models\UserSession;
use App\Services\Payments\GatewayManager;
use App\Services\TenantProvisioningService;
use App\Support\CurrentShop;
use App\Support\CurrentTenant;
use App\Support\PlanAccess;
use App\Support\SignupMailer;
use App\Support\SiteBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;

/**
 * Self-service sign-up: a plan, a business, an owner, and a trial.
 *
 * ---------------------------------------------------------------------------
 * The second public write on this site
 * ---------------------------------------------------------------------------
 *
 * And the first that creates accounts, so it gets the demo form's defences
 * plus two more:
 *
 *   1. A honeypot field, which costs a real person nothing.
 *   2. A broad throttle on the route, so a script that ignores the honeypot
 *      cannot hammer the validator.
 *   3. A narrow limit on *successful* sign-ups per address. Counting only
 *      successes means somebody fixing typos is never locked out, while a
 *      script still cannot mint a trial every second.
 *   4. A password rule, because this is the only account the owner will
 *      ever create without an administrator choosing the password for them.
 *
 * What it does not do is verify the email address. A trial is not money, and
 * a verification step is the commonest place a sign-up form loses people; the
 * limit above and the trial's own expiry are what bound the abuse.
 *
 * Works without JavaScript, like the rest of the public site.
 */
class RegisterController extends Controller
{
    /** The hidden field. Named like something a bot would want to fill. */
    private const HONEYPOT = 'website';

    private const MAX_SIGNUPS_PER_HOUR = 5;

    public function __construct(private readonly TenantProvisioningService $provisioning) {}

    public function create(Request $request): View
    {
        $plans = $this->plans();

        // Nothing for sale means nothing to sign up for.
        abort_if($plans->isEmpty(), 404);

        $period = $request->query('period');

        return view('register.index', [
            'site' => SiteBrand::get(),
            'plans' => $plans,
            'selected' => $plans->firstWhere('slug', $request->query('plan')) ?? $this->featured($plans),
            'period' => in_array($period, Plan::PERIODS, true) ? $period : Plan::MONTHLY,
            'terms' => Page::query()->active()->where('slug', 'like', '%terms%')->first(['title', 'slug']),
            'privacy' => Page::query()->active()->where('slug', 'like', '%privacy%')->first(['title', 'slug']),
            'footerPages' => Page::query()->footer()->get(['id', 'title', 'slug']),
        ]);
    }

    /**
     * Posted by public/assets/js/register.js (JSON) or as a plain form.
     *
     * Both get the same validation and the same account; only the reply
     * differs. A validation failure needs nothing here for the JSON case -
     * Laravel answers an Accept: application/json request with a 422 and the
     * errors bag, which app.js paints onto the fields.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        /*
         | Caught in the honeypot: store nothing and send them home. Telling a
         | bot it failed only teaches its author to leave the field blank.
         */
        if (filled($request->input(self::HONEYPOT))) {
            return $request->expectsJson()
                ? json_success('Thank you.', [], route('landing'))
                : redirect()->route('landing');
        }

        $limitKey = 'register:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limitKey, self::MAX_SIGNUPS_PER_HOUR)) {
            return $this->failed($request, 'email', 'Too many accounts have been created from this network. Please try again in an hour, or contact us.');
        }

        // Normalised first, so "Owner@Cafe.com" cannot slip past the
        // uniqueness check against "owner@cafe.com".
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        // The form shows +91 beside the box, so a bare ten-digit number is an
        // Indian mobile and is stored with its code; anything else as typed.
        $mobile = trim((string) $request->input('mobile'));

        if (preg_match('/^[6-9]\d{9}$/', $mobile)) {
            $request->merge(['mobile' => '+91 '.$mobile]);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:150', Rule::unique('users', 'email')],
            'mobile' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'plan' => ['required', 'string', Rule::exists('plans', 'slug')->where('is_active', true)->whereNull('tenant_id')->whereNull('deleted_at')],
            'period' => ['required', Rule::in(Plan::PERIODS)],
            'terms' => ['accepted'],
        ], [
            'email.unique' => 'An account with this email already exists. Try signing in instead.',
            'mobile.regex' => 'Enter a valid phone number.',
            'password.confirmed' => 'The two passwords do not match.',
            'terms.accepted' => 'Please accept the terms to continue.',
            'plan.exists' => 'That plan is no longer available. Please choose another.',
        ]);

        $plan = Plan::query()->onSale()->where('slug', $data['plan'])->firstOrFail();

        /*
         | A plan with no trial asks for money before anything else. Without
         | online payment there is no way to take it, and signing the owner
         | straight back out of an account they have just made would be the
         | worst possible first impression.
         */
        if ((int) $plan->trial_days <= 0 && ! app(GatewayManager::class)->platform()->isConfigured()) {
            return $this->failed($request, 'plan', 'This plan cannot be started online yet. Please book a demo and we will set it up with you.');
        }

        $logoPath = $request->file('logo')?->store('tenants', 'public');

        unset($data['logo']);
        $data['logo_path'] = $logoPath;

        $registered = false;

        try {
            $user = $this->provisioning->register($data, $plan, $data['period']);
            $registered = true;
        } catch (RuntimeException $e) {
            report($e);

            return $this->failed($request, 'email', 'Sign-up is not available right now. Please try again later or contact us.');
        } finally {
            if (! $registered && filled($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }
        }

        RateLimiter::hit($limitKey, 3600);

        Auth::login($user);

        // A fresh session id, so a pre-login session cannot be replayed.
        $request->session()->regenerate();

        UserSession::start($user, $request);
        LoginHistory::record(LoginHistory::SUCCESS, $user->email, $user, null, $request);

        $user->forceFill([
            'last_login_at' => now(),
            'last_seen_at' => now(),
            'login_count' => $user->login_count + 1,
        ])->saveQuietly();

        ActivityLog::record('auth.register', sprintf('Signed up "%s" on the %s plan', $user->tenant?->name, $plan->name), $user->tenant);

        // This request resolved who the visitor was while they were a guest.
        CurrentTenant::forget();
        CurrentShop::forget();
        PlanAccess::forget();

        // Welcome to the owner, "new sign-up" to us. Queued, and never throws.
        SignupMailer::send($user);

        // And on the platform's bell, and its phones.
        app(\App\Services\EventNotifier::class)->signedUp($user);

        [$route, $message] = (int) $plan->trial_days > 0
            ? ['admin.dashboard', sprintf(
                'Welcome! Your %d-day free trial of the %s plan has started. We have emailed your sign-in details.',
                (int) $plan->trial_days,
                $plan->name,
            )]
            : ['admin.subscription.mine', 'Welcome! Pick your plan below and pay to get started.'];

        if ($request->expectsJson()) {
            // The dashboard shows it again, for whoever missed the toast.
            $request->session()->flash('status', $message);

            return json_success($message, [], route($route));
        }

        return redirect()->route($route)->with('status', $message);
    }

    /** @return Collection<int, Plan> */
    private function plans(): Collection
    {
        return Plan::query()->onSale()->orderBy('sort_order')->orderBy('monthly_price')->get();
    }

    /**
     * The plan the landing page calls "most popular": the middle one.
     *
     * @param  Collection<int, Plan>  $plans
     */
    private function featured(Collection $plans): Plan
    {
        $plans = $plans->values();

        return $plans[$plans->count() >= 2 ? (int) floor(($plans->count() - 1) / 2) : 0];
    }

    private function failed(Request $request, string $field, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return json_error($message, [$field => [$message]]);
        }

        return back()
            ->withInput($request->except('password', 'password_confirmation'))
            ->withErrors([$field => $message]);
    }
}
