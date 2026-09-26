<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
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
        })();
    </script>

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
    {{-- Hex-escaped because a flash carries operator-entered text: a closing
         script tag inside it would end this block and be parsed as markup. --}}
    <script type="application/json" id="flash-messages">@json($flashed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
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
    <script type="application/json" id="realtime-config">@json($realtime, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
    <script>
        try {
            window.__realtime = JSON.parse(document.getElementById('realtime-config').textContent);
        } catch (e) { /* no live updates; the screens poll */ }
    </script>
@endif

@foreach ([
    'js/app.js', 'js/layout.js', 'js/modal.js', 'js/tabs.js',
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
     | The kitchen display's poll, its timers and its bell. Global for the
     | same reason as the floor plan: the board is swapped in as an AjaxList
     | fragment, and a <script> injected with that fragment would never run.
     */
    'js/kds.js',
    /*
     | The tender rows on a table bill: the remainder in the second row, and
     | the code a guest scans for whatever is going on UPI. Global for the
     | same reason again - the bill is opened in a modal from the floor plan,
     | and a <script> that arrives with a fragment never runs.
     */
    'js/table-bill.js',
] as $script)
    <script src="{{ asset('assets/'.$script) }}?v={{ filemtime(public_path('assets/'.$script)) }}"></script>
@endforeach

@stack('scripts')
</body>
</html>
