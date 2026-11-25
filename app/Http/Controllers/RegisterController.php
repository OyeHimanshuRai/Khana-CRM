<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Support\MobileNumber;
use App\Models\Page;
use App\Models\Plan;
use App\Services\Payments\GatewayManager;
use App\Services\TenantProvisioningService;
use App\Support\SignupMailer;
use App\Support\SignupOtp;
use App\Support\SiteBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
 * The email address is confirmed first, with a one-time code (SignupOtpController):
 * the form's Register button only appears once the code was entered, and this
 * method refuses an address that was not confirmed in the visitor's session.
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
            'canPayOnline' => app(GatewayManager::class)->platform()->isConfigured(),
            'selected' => $plans->firstWhere('slug', $request->query('plan')) ?? $this->featured($plans),
            'period' => in_array($period, Plan::PERIODS, true) ? $period : Plan::MONTHLY,
            // The Country dropdown, and the one the mobile number's rules start from.
            'countries' => Country::query()->orderBy('country_name')->get(['id', 'country_name', 'iso_code', 'phone_code', 'min_length', 'max_length']),
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

        // The country the number belongs to. A sign-up that names none (an old form, a script)
        // is an Indian one, as it always was.
        $countryId = $request->input('country_id') ?: Country::query()->where('iso_code', 'IN')->value('id');
        $request->merge(['country_id' => $countryId]);
        $country = Country::query()->find($countryId);

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'address_line1' => ['nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'country_id' => ['required', Rule::exists('countries', 'id')],
            // Chosen from the dropdowns: a state of that country, a city of that state.
            'state_id' => ['nullable', Rule::exists('states', 'id')->where('country_id', $countryId)],
            'city_id' => ['nullable', Rule::requiredIf(fn () => filled($request->input('state_id')) && City::query()->where('state_id', (int) $request->input('state_id'))->exists()), Rule::exists('cities', 'id')->where('state_id', (int) $request->input('state_id'))],
            'city' => ['nullable', 'string', 'max:90'],
            'state' => ['nullable', 'string', 'max:90'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:150', Rule::unique('users', 'email')],
            'mobile' => ['required', 'string', 'max:25', function ($attribute, $value, $fail) use ($country) {
                if ($country === null || MobileNumber::parse((string) $value, $country) === null) {
                    $fail('Enter a valid '.($country?->country_name ?? '').' mobile number.');
                }
            }],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'plan' => ['required', 'string', Rule::exists('plans', 'slug')->where('is_active', true)->whereNull('tenant_id')->whereNull('deleted_at')],
            'period' => ['required', Rule::in(Plan::PERIODS)],
            // How they start: the free trial (default), or paying for the plan now.
            'start' => ['nullable', Rule::in(['trial', 'paid'])],
            'terms' => ['accepted'],
        ], [
            'email.unique' => 'An account with this email already exists. Try signing in instead.',
            'country_id.exists' => 'Choose a country from the list.',
            'state_id.exists' => 'Choose a state from the list.',
            'city_id.exists' => 'Choose a city from the list.',
            'city_id.required' => 'Choose a city from the list.',
            'terms.accepted' => 'Please accept the terms to continue.',
            'plan.exists' => 'That plan is no longer available. Please choose another.',
        ]);

        // The address must have been confirmed with a one-time code, in this visitor's session.
        if ($request->session()->get(SignupOtp::SESSION_KEY) !== $data['email']) {
            return $this->failed($request, 'email', 'Please verify your email with the 6-digit code first.');
        }

        // What is stored: the country and its calling code, and the number in international form.
        $mobile = MobileNumber::parse($data['mobile'], $country);
        $data['mobile'] = $mobile['stored'];
        $data['mobile_country_code'] = $mobile['code'];
        $data['country'] = $country->country_name;

        if (filled($data['state_id'] ?? null)) {
            $data['state'] = State::query()->whereKey($data['state_id'])->value('state_name');
            $data['city'] = filled($data['city_id'] ?? null)
                ? City::query()->whereKey($data['city_id'])->value('city_name')
                : null;
        }

        $plan = Plan::query()->onSale()->where('slug', $data['plan'])->firstOrFail();

        // Nothing chosen: the trial where the plan has one, paying where it does not.
        $start = ($data['start'] ?? null) ?: ((int) $plan->trial_days > 0 ? 'trial' : 'paid');
        $payOnline = app(GatewayManager::class)->platform()->isConfigured();

        /*
         | A plan with no trial asks for money before anything else. Without
         | online payment there is no way to take it, and signing the owner
         | straight back out of an account they have just made would be the
         | worst possible first impression.
         */
        if ((int) $plan->trial_days <= 0 && ! $payOnline) {
            return $this->failed($request, 'plan', 'This plan cannot be started online yet. Please contact support and we will set it up with you.');
        }

        if ($start === 'paid' && ! $payOnline) {
            return $this->failed($request, 'start', 'Paying online is not available yet. Please start the free trial, or contact support.');
        }

        if ($start === 'trial' && (int) $plan->trial_days <= 0) {
            return $this->failed($request, 'start', 'This plan has no free trial. Please choose to pay now.');
        }

        $logoPath = $request->file('logo')?->store('tenants', 'public');

        unset($data['logo']);
        $data['logo_path'] = $logoPath;

        $registered = false;

        try {
            $user = $this->provisioning->register($data, $plan, $data['period'], $start);
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

        ActivityLog::record('auth.register', sprintf('Signed up "%s" on the %s plan', $user->tenant?->name, $plan->name), $user->tenant);

        // The code already proved the address is theirs, so the account is active at once.
        // Nobody is signed in by signing up: they go back to the home page and sign in.
        $user->forceFill(['email_verified_at' => now()])->saveQuietly();
        $request->session()->forget(SignupOtp::SESSION_KEY);

        // The welcome email (sent now, so a failure is known), and "new sign-up" to us
        // (queued). Never throws.
        $emailed = SignupMailer::send($user);

        // And on the platform's bell, and its phones.
        app(\App\Services\EventNotifier::class)->signedUp($user);

        // Paying up front: they are signed in and taken straight to the payment, so the plan can start.
        if ($start === 'paid') {
            \Illuminate\Support\Facades\Auth::login($user);
            $request->session()->regenerate();

            $payUrl = route('admin.subscription.mine', ['pay' => 1]);
            $payMessage = 'Your account is ready. Complete the payment to start your plan.';

            return $request->expectsJson()
                ? json_success($payMessage, [], $payUrl)
                : redirect($payUrl)->with('status', $payMessage);
        }

        $trial = (int) $plan->trial_days > 0;

        // What the home page shows, as a notice that stays until it is closed.
        $notice = [
            'email' => $user->email,
            'sent' => $emailed,
            'plan' => $plan->name,
            'trial_days' => $trial ? (int) $plan->trial_days : 0,
            'verified' => true,
        ];

        $message = "Thank you for registering! Your email is verified and your account is ready. Sign in with {$user->email} to get started.";

        $request->session()->flash('signup_notice', $notice);

        if ($request->expectsJson()) {
            return json_success($message, [], route('landing'));
        }

        return redirect()->route('landing');
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
            ->withInput($request->except('password'))
            ->withErrors([$field => $message]);
    }
}
