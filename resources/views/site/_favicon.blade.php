{{-- The tab icon from Settings > Logos & Images, or the one shipped with the app. --}}
@if ($site['favicon_type'])
    <link rel="icon" type="{{ $site['favicon_type'] }}" href="{{ $site['favicon'] }}">
@else
    <link rel="icon" href="{{ $site['favicon'] }}" sizes="any">
@endif
