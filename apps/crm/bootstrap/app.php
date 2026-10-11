<?php

use App\Http\Middleware\EnsureCustomerBelongsToShop;
use App\Http\Middleware\EnsureGuestOutletIsEntitled;
use App\Http\Middleware\EnsureIpNotBlocked;
use App\Http\Middleware\EnsureModuleIsEnabled;
use App\Http\Middleware\EnsureShopIsEntitled;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ShareStorefrontShop;
use App\Http\Middleware\TrackUserSession;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        /*
        | The API (§2, §15, §21). Token-authenticated through Sanctum, and
        | deliberately small - see routes/api.php.
        */
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        /*
        | Registers /broadcasting/auth and routes/channels.php.
        |
        | Harmless when BROADCAST_CONNECTION is `null`, which is the default:
        | the endpoint exists, nothing subscribes to it, and the screens carry
        | on polling. See config/broadcasting.php.
        */
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Not passed as `web:` above because that also makes it the
            // implicit "no route matched" fallback target; the storefront
            // is additive to the admin app, not a replacement for it.
            Route::middleware('web')->group(__DIR__.'/../routes/shop.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // Runs after `admin`: a suspended company's staff are signed out.
            'tenant.active' => EnsureTenantIsActive::class,
            /*
            | The per-outlet half of the same question: has this outlet bought
            | CRM? Accounts sells per outlet and the kit keeps its answer in
            | Entitlements, so a lapsed branch is stopped here - without
            | signing anybody out, because their other branches are paid up
            | and trading. Named apart from the kit's own `entitled` alias,
            | which reads the kit's tenancy context; see the middleware.
            */
            'shop.entitled' => EnsureShopIsEntitled::class,
            /*
            | The same question for a guest - the QR stickers and the
            | storefront - where nobody is signed in and the outlet is the
            | one the guest is at. The rule is EnsureShopIsEntitled's.
            */
            'guest.entitled' => EnsureGuestOutletIsEntitled::class,
            /*
            | The shop-level half of access. Reads the route's own
            | `permission:` middleware and refuses it when the branch does not
            | run that line of business - so a disabled module is unreachable
            | by typed URL and by API, not merely missing from the sidebar.
            | Applied once to the whole admin group; see routes/web.php.
            */
            'module' => EnsureModuleIsEnabled::class,
            'ip.allowed' => EnsureIpNotBlocked::class,
            'customer.shop' => EnsureCustomerBelongsToShop::class,
            'storefront.shop' => ShareStorefrontShop::class,
            /*
            | Sanctum's token scopes, for the API.
            |
            | Every API route names the ability it needs, so a token minted
            | for an aggregator can read the menu and post orders and do
            | nothing else. See routes/api.php.
            */
            'ability' => CheckForAnyAbility::class,
            'abilities' => CheckAbilities::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        /*
        | Terminable: it writes the presence timestamps after the response
        | has gone out, so tracking never sits on the request's critical path.
        */
        $middleware->web(append: [
            TrackUserSession::class,
        ]);

        /*
        | RFC 8058 one-click unsubscribe. The POST arrives from the
        | recipient's mail provider, which has no session with us and no
        | token to send - so CSRF cannot apply. What authorises it instead is
        | the 64-character subscription token in the path, which is the same
        | secret as the unsubscribe link itself.
        |
        | Scoped to the token form only: POST /newsletter/unsubscribe, the
        | one our own page submits, keeps its CSRF check.
        */
        $middleware->validateCsrfTokens(except: [
            'newsletter/unsubscribe/*',
            /*
             | A payment webhook arrives from a provider's server, which has
             | no session and therefore no token to send. It is not
             | unprotected: the gateway checks an HMAC of the body against a
             | secret only the provider holds, and refuses everything else.
             | See App\Http\Controllers\PaymentWebhookController.
             */
            'payments/webhook',
        ]);

        /*
        | Two front doors now: the admin app and, per shop, the storefront.
        | A route name is the only cheap signal available this early in the
        | pipeline to tell which one a guest was headed for - every
        | storefront route is named shop.* (see routes/shop.php) and carries
        | a {shop} slug in its own path, which a bare admin route never has.
        */
        $middleware->redirectGuestsTo(function (Request $request) {
            /*
            | Nothing to redirect an integration to.
            |
            | Returning null here lets the framework throw, and the handler
            | below turns that into a 401 with a JSON body. A 302 to a login
            | page is a confusing answer to a script: it reads as success,
            | and the integrator debugs an HTML page for an afternoon.
            */
            if ($request->is('api/*')) {
                return null;
            }

            $shop = $request->route('shop');

            return str_starts_with((string) $request->route()?->getName(), 'shop.') && $shop
                ? route('shop.login', ['shop' => $shop])
                : route('admin.login');
        });

        $middleware->redirectUsersTo(function (Request $request) {
            $shop = $request->route('shop');

            return str_starts_with((string) $request->route()?->getName(), 'shop.') && $shop
                ? route('shop.home', ['shop' => $shop])
                : route('admin.dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Normalise the framework's own failures into the same envelope that
        // json_success()/json_error() produce, so the front-end has exactly
        // one response shape to deal with.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error($e->getMessage(), $e->errors(), $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            /*
            | An integration gets told what is actually wrong with its token.
            | "Your session has expired" is the browser's answer and means
            | nothing to a script that never had a session.
            */
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'This request needs a valid API token. Send it as: Authorization: Bearer <token>.',
                ], 401);
            }

            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('Your session has expired. Please sign in again.', [], 401);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $message = match ($e->getStatusCode()) {
                419 => 'Your session has expired. Please refresh the page and try again.',
                429 => 'Too many requests. Please slow down and try again shortly.',
                default => $e->getMessage() ?: 'Something went wrong.',
            };

            return ApiResponse::error($message, [], $e->getStatusCode());
        });
    })->create();
