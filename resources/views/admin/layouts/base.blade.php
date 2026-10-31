<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b0b0d" media="(prefers-color-scheme: dark)">
    {{--
        Speculation rules: the page behind a sidebar link is fetched while the
        pointer is still on its way to the click (hover for a moment, or the press
        itself), so most navigations arrive already downloaded. Prefetch, not
        prerender: an admin page runs scripts that poll and write "last seen", and
        none of that should happen for a page nobody opened.

        Only the sidebar's own links - screens that list things - and never an
        export, a print, or sign-out. A browser without the API ignores the block.
    --}}
    @php
        $specBase = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
        $specRules = ['prefetch' => [[
            'where' => ['and' => [
                ['href_matches' => $specBase.'/admin/*'],
                ['selector_matches' => '.sidebar-nav a'],
                ['not' => ['href_matches' => $specBase.'/admin/*/export*']],
                ['not' => ['href_matches' => $specBase.'/admin/*/print*']],
                ['not' => ['href_matches' => $specBase.'/admin/logout*']],
            ]],
            'eagerness' => 'moderate',
        ]]];
    @endphp
    <script type="speculationrules">@json($specRules, JSON_UNESCAPED_SLASHES)</script>
    @php
        /*
         | Tab icon and title, both from Settings > General when they are set.
         |
         | The uploaded favicon's filename is random, so replacing it changes
         | the URL - which is what gets past the browser's very sticky favicon
         | cache without any versioning of our own.
         */
        $favicon = App\Support\CompanySettings::fileUrl('favicon');

        $faviconType = $favicon
            ? match (strtolower(pathinfo(parse_url($favicon, PHP_URL_PATH), PATHINFO_EXTENSION))) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                default => 'image/x-icon',
            }
            : null;

        $siteName = App\Models\Setting::get('company_name', config('app.name'));
    @endphp

    <title>@yield('title', 'Admin') &middot; {{ $siteName }}</title>

    @if ($favicon)
        <link rel="icon" type="{{ $faviconType }}" href="{{ $favicon }}">
    @else
        {{-- Nothing uploaded: the file shipped with the app. --}}
        <link rel="icon" href="{{ url('favicon.ico') }}" sizes="any">
    @endif

    <script>
        /* Applied before first paint so a dark-mode reload never flashes white.
           Kept inline and tiny on purpose - it must run before the stylesheets
           below are applied. Mirrors the keys used by layout.js. */
        (function () {
            try {
                var t = localStorage.getItem('erp.theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();    </script>

    @php
        // filemtime() cache-busts during development without a build step.
        $assets = ['css/theme.css', 'css/layout.css', 'css/app.css', 'css/pos.css', 'css/floor-plan.css', 'css/kds.css'];
    @endphp

    @foreach ($assets as $file)
        <link rel="stylesheet" href="{{ asset('assets/'.$file) }}?v={{ filemtime(public_path('assets/'.$file)) }}">
    @endforeach
    @stack('styles')
</head>
<body class="@yield('body-class')">

<script>
    /* Restores the collapsed rail before the shell paints, so a reload does
       not animate the sidebar shut. Body-level, hence not in <head>. */
    (function () {
        try {
            if (localStorage.getItem('erp.sidebar.collapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    })();
</script>

@yield('body')

@php
    // Server-side flashes handed to the toast layer. Field-level validation
    // errors are left to the inline @error spans / the AJAX handler.
    $flashed = collect(['success' => 'status', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'])
        ->filter(fn ($key) => session()->has($key))
        ->map(fn ($key, $type) => ['type' => $type, 'message' => session($key)])
        ->values();
@endphp

@if ($flashed->isNotEmpty())
    <script type="application/json" id="flash-messages">{!! $flashed->toJson() !!}</script>
@endif

{{--
    Live updates, if this install runs a socket server (§8, §9).

    The config block is rendered only when broadcasting is actually
    configured; without it public/assets/js/realtime.js returns immediately
    and every screen polls exactly as it did before. That is the default and
    it is a working configuration - see config/broadcasting.php.

    Only the app KEY is here, never the secret: the key is public by design
    (the browser must send it to connect) and the secret signs server-side
    only. Who may listen to what is decided at /broadcasting/auth against the
    session cookie, in routes/channels.php.
--}}
@php
    $realtime = config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key')
        ? [
            'key' => config('broadcasting.connections.reverb.key'),
            'host' => config('broadcasting.connections.reverb.options.host') ?: request()->getHost(),
            'port' => (int) config('broadcasting.connections.reverb.options.port', 443),
            'scheme' => config('broadcasting.connections.reverb.options.scheme', 'https'),
            'authEndpoint' => url('/broadcasting/auth'),
        ]
        : null;
@endphp

@if ($realtime)
    <script type="application/json" id="realtime-config">{!! json_encode($realtime, JSON_UNESCAPED_SLASHES) !!}</script>
    <script>
        try {
            window.__realtime = JSON.parse(document.getElementById('realtime-config').textContent);
        } catch (e) { /* no live updates; the screens poll */ }
    </script>
@endif

{{--
    This person's own notification settings (Profile > Notifications), for
    notify-sound.js. Only switches and a language - nothing sensitive - and
    only for somebody signed in.
--}}
@auth
    <script type="application/json" id="notify-prefs">{!! json_encode(\App\Support\NotificationPreferences::for(auth()->user()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    <script>
        try {
            window.__notifyPrefs = JSON.parse(document.getElementById('notify-prefs').textContent);
        } catch (e) { /* defaults: everything on */ }
    </script>
@endauth

@foreach ([
    /*
     | The shapes drawn while something loads (a dialog's fields, a list of
     | people). Before modal.js and layout.js, which ask for them.
     */
    'js/skeleton.js',
    'js/app.js',
    /*
     | On/off switches in a listing answer at once and carry an Undo. After
     | app.js, whose ajax events it listens to.
     */
    'js/toggles.js',
    'js/layout.js', 'js/modal.js', 'js/tabs.js',
    'js/permission-matrix.js', 'js/ajax-list.js',
    'js/settings.js', 'js/user-security.js', 'js/crud-forms.js',
    'js/line-items.js', 'js/customer-picker.js', 'js/modifier-options.js',
    /*
     | The floor plan's drag-to-arrange. Loaded globally rather than per page
     | because the plan is also swapped in as an AjaxList fragment, and a
     | <script> injected with that fragment would never run.
     */
    'js/floor-plan.js',
    /*
     | The live audience count on the campaign form. Global because the form
     | is injected into a modal, and a <script> that arrives that way never
     | runs.
     */
    'js/campaigns.js',
    /*
     | Reveals a newly created API token once. Sanctum stores a hash, so there
     | is no second chance - see public/assets/js/api-tokens.js.
     */
    'js/api-tokens.js',
    /*
     | The WebSocket client, before kds.js so window.Realtime exists by the
     | time the board asks whether a socket is live. Does nothing at all
     | unless the config block above rendered, which is the default.
     */
    'js/realtime.js',
    /*
     | Chimes and the spoken "New order, Table 4", for the bell and the
     | kitchen board alike. Before both, so window.NotifySound is there when
     | they ring. Reads window.__notifyPrefs, printed in the @auth block
     | just above this list.
     */
    'js/notify-sound.js',
    /*
     | The kitchen display's poll, its timers and its bell. Global for the
     | same reason as the floor plan: the board is swapped in as an AjaxList
     | fragment, and a <script> injected with that fragment would never run.
     */
    'js/kds.js',
    /*
     | The bell, live: a short poll that updates the count and toasts new
     | alerts. Returns at once on pages with no bell (sign-in).
     */
    'js/alerts-live.js',
    /*
     | The "i" popovers, and the folding of long form hints into them. Global
     | because forms arrive in modals, which it watches for.
     */
    'js/info.js',
    /*
     | "Notify me on this device" through Firebase. Returns at once unless
     | the header printed window.__firebase, which it only does when the
     | platform has configured Firebase.
     */
    'js/firebase-push.js',
    /*
     | Ctrl/Cmd+K: jump to any screen this person may open. Built from the
     | sidebar, so it can only ever offer what the sidebar offers.
     */
    'js/command-menu.js',
    /*
     | The progress bar while the browser moves to another page. Last on
     | purpose: it only starts for a navigation nothing else has already
     | claimed, which it can only tell once every other click and submit
     | handler has had its turn at preventDefault().
     */
    'js/topbar.js',
] as $script)
    <script src="{{ asset('assets/'.$script) }}?v={{ filemtime(public_path('assets/'.$script)) }}"></script>
@endforeach

@stack('scripts')
</body>
</html>
