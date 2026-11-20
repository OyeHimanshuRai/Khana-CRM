@props([
    /* Small line under the name; null hides it. */
    'sub' => 'Administration',
    /* Where the logo links to, or null for a plain block (the login screen). */
    'href' => null,
    /* The sidebar: the whole Site Logo, with the square mark kept for the collapsed rail. */
    'wide' => false,
])

@php
    use App\Models\Setting;
    use App\Support\CompanySettings;

    /*
     | The square slot beside the name wants a mark, not a wordmark: the
     | Company Icon from Settings > Logos & Images, and the Site Logo only
     | when no icon was uploaded - a logo that already spells the name would
     | say it twice beside the name. Null when neither is set or the file
     | has gone missing; the lettermark below stands in, so the chrome never
     | renders empty or broken.
     */
    $logo = CompanySettings::fileUrl('company_icon') ?? CompanySettings::fileUrl('site_logo');

    // The configured company name, falling back to the app's own.
    $name = Setting::get('company_name', config('app.name'));

    $mark = Str::upper(Str::substr($name, 0, 3));

    // Only where asked for (the sidebar), and only when a Site Logo was uploaded.
    $wideLogo = $wide ? CompanySettings::fileUrl('site_logo') : null;
@endphp

<{{ $href ? 'a' : 'div' }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['brand']) }}
>
    @if ($wideLogo)
        <img class="brand-wide" src="{{ $wideLogo }}" alt="{{ $name }}">
    @endif

    <span class="brand-mark {{ $logo ? 'has-logo' : '' }} {{ $wideLogo ? 'brand-rail' : '' }}" aria-hidden="true">
        @if ($logo)
            <img src="{{ $logo }}" alt="">
        @else
            {{ $mark }}
        @endif
    </span>

    <span class="brand-text {{ $wideLogo ? 'brand-text-hidden' : '' }}">
        <span class="brand-name">{{ $name }}</span>
        @if ($sub)
            <span class="brand-sub">{{ $sub }}</span>
        @endif
    </span>
</{{ $href ? 'a' : 'div' }}>
