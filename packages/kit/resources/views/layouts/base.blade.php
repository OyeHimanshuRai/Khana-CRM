{{--
    The page every Khana product draws on: one look across Accounts, CRM and
    ERP. The CSS and plain JavaScript are the kit's (packages/kit/ui), served
    from public/vendor/khana.

    Sections: title, body-class, body. Stacks: styles, scripts.
--}}
@php
    use Khana\Kit\Ui\Ui;

    $product = Ui::productName();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">

    <title>@yield('title', $product) &middot; Khana {{ $product }}</title>

    <link rel="icon" href="{{ config('khana.ui.favicon') ?: url('favicon.ico') }}" sizes="any">

    <script>
        /* Applied before first paint so a dark-mode reload never flashes
           white. Same key in every product, so the choice follows the
           person from CRM to ERP. */
        (function () {
            try {
                var t = localStorage.getItem('erp.theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>

    @foreach (Ui::STYLES as $file)
        <link rel="stylesheet" href="{{ Ui::asset($file) }}">
    @endforeach

    @stack('styles')
</head>
<body class="@yield('body-class')">

<script>
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
    // Server-side flashes handed to the toast layer.
    $flashed = collect(['success' => 'status', 'error' => 'error', 'warning' => 'warning', 'info' => 'info'])
        ->filter(fn ($key) => session()->has($key))
        ->map(fn ($key, $type) => ['type' => $type, 'message' => session($key)])
        ->values();
@endphp

@if ($flashed->isNotEmpty())
    {{-- Hex-escaped: a flash can carry text someone typed. --}}
    <script type="application/json" id="flash-messages">@json($flashed, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
@endif

@foreach (Ui::SCRIPTS as $file)
    <script src="{{ Ui::asset($file) }}"></script>
@endforeach

@stack('scripts')
</body>
</html>
