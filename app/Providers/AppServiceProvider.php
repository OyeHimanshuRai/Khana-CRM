<?php

namespace App\Providers;

use App\Listeners\RecordOutgoingMail;
use App\Models\Alert;
use App\Models\User;
use App\Services\Payments\GatewayManager;
use App\Services\Sms\SmsManager;
use App\Services\Whatsapp\WhatsAppManager;
use App\Support\EmailLogger;
use App\Support\MailConfigurator;
use App\Support\PaymentSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * One password rule for staff accounts.
     *
     * Production asks for 10+ characters with both cases and a number; local
     * and test keep the old 8-character minimum so demo data and fixtures do
     * not have to change.
     */
    private function registerPasswordPolicy(): void
    {
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(10)->letters()->mixedCase()->numbers()
            : Password::min(8));
    }

    /**
     * Surface N+1 queries on a developer machine without ever breaking a page.
     *
     * A lazy load of a relation is logged with the model and relation so it
     * can be fixed with eager loading. Local only: production never pays for
     * the tracking, and tests are not changed.
     */
    private function registerQueryDiagnostics(): void
    {
        if (! $this->app->isLocal()) {
            return;
        }

        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            Log::warning('N+1: lazy loaded '.$model::class.'::'.$relation);
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
        | Shared, because it holds the "currently in flight" pointer that
        | links a MessageSending to its MessageSent - and lets a caller that
        | catches a send failure mark the right row. Laravel dispatches no
        | event for a failed send, so there is nothing else to correlate on.
        */
        $this->app->singleton(EmailLogger::class);

        /*
        | Shared, and it has to be.
        |
        | GatewayManager resolves one provider from config and remembers it.
        | Injected fresh per class, that memo would be pointless - but worse,
        | a request could render a screen against one instance and verify the
        | callback against another, and a test could never stand a fake in
        | front of the real one at all. One request, one gateway.
        */
        $this->app->singleton(GatewayManager::class);

        // Same argument, one floor down: a request that offered to send a
        // code through one gateway and checked it against another would be
        // a bug nobody could reproduce. See App\Services\Sms\SmsManager.
        $this->app->singleton(SmsManager::class);

        // And WhatsApp, for the same reason again. See config/whatsapp.php
        // for why it is a separate gateway rather than SMS with another URL.
        $this->app->singleton(WhatsAppManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPasswordPolicy();
        $this->registerQueryDiagnostics();
        $this->registerSuperAdmin();
        $this->registerApiRateLimit();
        $this->registerGuestRateLimits();
        $this->registerDateQueries();
        $this->registerBladeDirectives();
        $this->registerMailLogging();
        $this->registerViewComposers();

        // Settings > General owns the mailer; without this the screen stores
        // credentials that nothing reads. .env stays the fallback.
        MailConfigurator::apply();

        /*
        | And the same for the payment provider (§11). The person holding the
        | Razorpay keys is the restaurant's owner; the person who can edit
        | .env is whoever deployed it. Where .env speaks it still wins - an
        | environment variable is a deployment decision, and a form should not
        | be able to undo one silently.
        */
        PaymentSettings::apply();

        // Laravel's bundled paginator views target Tailwind/Bootstrap, which
        // this project does not compile. Use the design-system markup.
        Paginator::defaultView('vendor.pagination.erp');
        Paginator::defaultSimpleView('vendor.pagination.erp');
    }

    /**
     * `whereOnDate()` - whereDate() that can use an index.
     *
     * whereDate('invoiced_at', '>=', $d) compiles to DATE(invoiced_at) >= ?,
     * and wrapping the column in a function means MySQL cannot use any index
     * on it: every report, ledger and dashboard tile read the whole table to
     * find one day. This compares the bare column against day boundaries
     * instead - `>= day` and `< next day` - which is the same answer and an
     * index range scan.
     *
     * Date-only boundaries on purpose, so it is right for both kinds of
     * column on both databases: a DATE and a TIMESTAMP on MySQL, and on
     * SQLite a date stored as '2026-10-24' as well as one stored as
     * '2026-10-24 00:00:00'. A boundary with a time on it would compare
     * '2026-10-24' as less than '2026-10-24 00:00:00' there and lose the day.
     *
     * Same signature as whereDate(): (column, value) means equals. Anything
     * that is not a date falls back to whereDate() rather than throwing, so a
     * mangled ?from= in a URL behaves exactly as it always did.
     */
    private function registerDateQueries(): void
    {
        QueryBuilder::macro('whereOnDate', function (string $column, $operator, $value = null) {
            /** @var QueryBuilder $this */
            if (func_num_args() === 2) {
                [$value, $operator] = [$operator, '='];
            }

            try {
                $day = Carbon::parse($value)->startOfDay();
            } catch (\Throwable) {
                return $this->whereDate($column, $operator, $value);
            }

            $from = $day->toDateString();
            $next = $day->copy()->addDay()->toDateString();

            return match ($operator) {
                '=' => $this->where($column, '>=', $from)->where($column, '<', $next),
                '>=' => $this->where($column, '>=', $from),
                '>' => $this->where($column, '>=', $next),
                '<' => $this->where($column, '<', $from),
                '<=' => $this->where($column, '<', $next),
                default => $this->whereDate($column, $operator, $value),
            };
        });
    }

    /**
     * How hard an integration may poll (§2, §21).
     *
     * Keyed on the TOKEN, not the user. One restaurant may connect an
     * aggregator, a captain's app and a kiosk to the same account, and a
     * limit shared between them means the busiest one starves the others -
     * which looks to everybody involved like the API being unreliable.
     *
     * Sixty a minute is generous for anything doing real work and useless for
     * a loop. An aggregator syncing the menu every thirty seconds uses two.
     *
     * Falls back to the IP for an unauthenticated call, which only reaches
     * here on its way to a 401 anyway.
     */
    /**
     * The guest journey's limits, counted per table rather than per address.
     *
     * Every guest on a restaurant's Wi-Fi shares one public address, so a
     * per-address limit of ten orders a minute was the whole restaurant's
     * limit: on a busy evening the eleventh table to order was refused. Each
     * limit now counts one sitting (the guest's own session) - the stuck
     * finger it exists for - and a scan counts one sticker. A ceiling twenty
     * times higher per address still stops a script.
     *
     * Used on the routes as throttle:guest-cart, throttle:guest-order and so on.
     */
    private function registerGuestRateLimits(): void
    {
        $perSitting = [
            'guest-cart' => 60,
            'guest-order' => 10,
            'guest-live' => 60,
            'guest-otp-send' => 5,
            'guest-otp' => 20,
            'guest-pay' => 10,
            'guest-pay-confirm' => 30,
            'guest-feedback' => 20,
        ];

        foreach ($perSitting as $name => $perMinute) {
            RateLimiter::for($name, function (Request $request) use ($name, $perMinute) {
                $sitting = $request->hasSession()
                    ? $request->session()->get(\App\Http\Controllers\TableScanController::SESSION_KEY)
                    : null;

                return [
                    Limit::perMinute($perMinute)->by($name.':'.($sitting ?: 'ip:'.$request->ip())),
                    Limit::perMinute($perMinute * 20)->by($name.':address:'.$request->ip()),
                ];
            });
        }

        RateLimiter::for('guest-scan', fn (Request $request) => [
            Limit::perMinute(30)->by('guest-scan:'.$request->route('token')),
            Limit::perMinute(600)->by('guest-scan:address:'.$request->ip()),
        ]);
    }

    private function registerApiRateLimit(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute(60)->by(
                $token?->id ? 'token:'.$token->id : 'ip:'.$request->ip()
            );
        });
    }

    /**
     * Give the super admin role every permission implicitly.
     *
     * Returning null (not false) for everyone else is important: it lets the
     * check fall through to Spatie's normal role/permission resolution
     * instead of short-circuiting it as a denial.
     */
    private function registerSuperAdmin(): void
    {
        Gate::before(function (User $user) {
            return $user->hasRole(User::SUPER_ADMIN) ? true : null;
        });
    }

    /**
     * Record every outbound email.
     *
     * Registered here rather than discovered: bootstrap/app.php does not call
     * withEvents(), so nothing in app/Listeners is picked up automatically -
     * and being explicit keeps the wiring findable.
     */
    private function registerMailLogging(): void
    {
        Event::listen(MessageSending::class, [RecordOutgoingMail::class, 'sending']);
        Event::listen(MessageSent::class, [RecordOutgoingMail::class, 'sent']);
    }

    /**
     * The unread badge on the header bell.
     *
     * A composer rather than a variable passed by every controller: the
     * header is included by the layout, so there is no one controller that
     * could supply it. The list itself is fetched only when the bell is
     * opened - see layout.js - so an ordinary page load pays for a count and
     * nothing more.
     */
    private function registerViewComposers(): void
    {
        View::composer('admin.partials.header', function ($view) {
            $user = auth()->user();

            $view->with('alertCount', $user instanceof User ? Alert::badgeCount($user) : 0);

            // Where alerts-live.js starts counting "new" from, so the alerts
            // already on the page are not toasted again on the first poll.
            $view->with('alertLatestId', $user instanceof User
                ? (int) Alert::acrossShopsFor($user, 1)->max('id')
                : 0);

            // Only printed when the platform has configured Firebase - see
            // public/assets/js/firebase-push.js.
            $firebase = \App\Models\FirebaseSetting::current();

            $view->with('firebasePush', $user instanceof User && $firebase->isReady() ? [
                'config' => $firebase->webConfig(),
                'vapidKey' => $firebase->vapid_key,
                'swUrl' => asset('firebase-messaging-sw.js'),
                'scope' => rtrim(parse_url(url('/admin'), PHP_URL_PATH) ?: '/admin', '/').'/',
                'registerUrl' => route('admin.devices.fcm'),
            ] : null);
        });
    }

    /**
     * Blade helpers for button-level permission checks.
     *
     *   @allows('sales.orders.approve')  ... @endallows
     *   @anyallows(['a', 'b'])           ... @endanyallows
     *
     * `can` already exists in Laravel; these read better for bare permission
     * strings and keep the intent obvious in dense markup.
     */
    private function registerBladeDirectives(): void
    {
        Blade::if('allows', function (string $permission) {
            return auth()->check() && auth()->user()->can($permission);
        });

        Blade::if('anyallows', function (array $permissions) {
            if (! auth()->check()) {
                return false;
            }

            return auth()->user()->canAny($permissions);
        });
    }
}
