{{--
    The public site's header: landing, policy pages, sign-up.

    The logo and name come from Settings (App\Support\SiteBrand), so changing
    them there changes every public page at once.

    @param array        $site     SiteBrand::get()
    @param array        $links    label => href, the section links (optional)
    @param array        $actions  [label, href, 'ghost'|'solid'] buttons on the right
--}}
@php
    $actionIcons = [
        'Sign in' => 'fa-arrow-right-to-bracket',
        'Start free trial' => 'fa-rocket',
        'See pricing' => 'fa-tags',
        'Pricing' => 'fa-tags',
        'Book a demo' => 'fa-calendar-check',
        'View pricing' => 'fa-tags',
        'Home' => 'fa-house',
    ];
    $links = $links ?? [
        'How it works' => route('landing.how'),
        'Features' => route('landing.features'),
        'Billing' => route('landing.billing'),
        'Pricing' => route('pricing'),
        'FAQ' => route('landing.faq'),
        'Book a demo' => route('demo'),
    ];
    $actions = $actions ?? [['Sign in', route('login'), 'solid']];
@endphp

<header class="lp-header">
    <div class="lp-wrap lp-header-row">
        <a href="{{ route('landing') }}" class="lp-brand">
            @if ($site['logo'])
                <img src="{{ $site['logo'] }}" alt="{{ $site['name'] }}" height="34">
            @else
                <span class="lp-brand-text">{{ $site['name'] }}</span>
            @endif
        </a>

        @if ($links)
            <nav class="lp-nav" aria-label="Primary">
                @foreach ($links as $label => $href)
                    <a href="{{ $href }}">{{ $label }}</a>
                @endforeach
            </nav>
        @endif

        <div class="lp-header-cta">
            @foreach ($actions as [$label, $href, $style])
                <a class="lp-btn lp-btn-{{ $style }}" href="{{ $href }}">
                    @if (isset($actionIcons[$label]))
                        <i class="fa-solid {{ $actionIcons[$label] }}" aria-hidden="true"></i>
                    @endif
                    {{ $label }}
                </a>
            @endforeach
        </div>

        @if ($links)
            {{--
                The small-screen menu. A <details> rather than a script: the
                nav above is hidden below 860px, and this opens with no
                JavaScript on the page.
            --}}
            <details class="lp-menu">
                <summary aria-label="Menu">
                    <i class="fa-solid fa-bars lp-menu-open" aria-hidden="true"></i>
                    <i class="fa-solid fa-xmark lp-menu-close" aria-hidden="true"></i>
                </summary>

                <nav class="lp-menu-panel" aria-label="Sections">
                    @foreach ($links as $label => $href)
                        <a href="{{ $href }}">{{ $label }}</a>
                    @endforeach
                    @foreach ($actions as [$label, $href])
                        <a href="{{ $href }}">
                            @if (isset($actionIcons[$label]))
                                <i class="fa-solid {{ $actionIcons[$label] }}" aria-hidden="true"></i>
                            @endif
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>
            </details>
        @endif
    </div>
</header>
