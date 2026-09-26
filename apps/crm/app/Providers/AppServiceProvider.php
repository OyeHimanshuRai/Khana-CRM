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
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        $this->registerSuperAdmin();
        $this->registerApiRateLimit();
        $this->registerSignupRateLimit();
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
     * How often one address may open an account (§21).
     *
     * A named limiter rather than `throttle:5,60` on the route, because the
     * honest answer is not the same in both places.
     *
     * In production five an hour from one address is far more than any real
     * restaurant needs and far less than a script wants: each post writes a
     * company, a branch, a login and a subscription, so a loop left running
     * overnight is a database full of businesses.
     *
     * Locally it is the wrong number entirely. The same form gets filled in a
     * dozen times in an afternoon while somebody is building or demonstrating
     * it, and the sixth attempt meeting Laravel's "Too Many Requests" page
     * reads as a broken feature rather than as a limit doing its job - which
     * is exactly how this was first reported.
     *
     * Keyed on the IP either way. Keying on the email address would let one
     * script open accounts all night simply by changing it.
     */
    private function registerSignupRateLimit(): void
    {
        RateLimiter::for('signup', function (Request $request) {
            return app()->environment('local', 'testing')
                ? Limit::perMinute(30)->by($request->ip())
                : Limit::perHour(5)->by($request->ip());
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
