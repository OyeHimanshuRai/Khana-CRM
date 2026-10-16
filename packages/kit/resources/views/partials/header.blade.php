@php
    use Khana\Kit\Ui\ProductSwitcher;
    use Khana\Kit\Ui\Ui;

    $user = auth()->user();
    $logout = config('khana.ui.logout', 'logout');
    $products = app()->bound(ProductSwitcher::class) ? app(ProductSwitcher::class)->links() : [];

    // The profile lives in Accounts: one person, one profile, whichever
    // product they are looking at.
    $accounts = config('khana.urls.accounts');
    $profile = config('khana.service') === 'accounts'
        ? (Route::has('profile') ? route('profile') : null)
        : (is_string($accounts) && $accounts !== '' ? rtrim($accounts, '/').'/profile' : null);
@endphp

<header class="header">
    <button type="button" class="header-btn sidebar-toggle-desktop" data-sidebar-toggle
            aria-label="Collapse sidebar" title="Collapse sidebar">
        <x-khana::icon name="panel-left" :size="19" />
    </button>

    <button type="button" class="header-btn sidebar-toggle-mobile" data-sidebar-toggle
            aria-label="Open menu" aria-controls="sidebar">
        <x-khana::icon name="menu" :size="20" />
    </button>

    @hasSection('header-context')
        @yield('header-context')
    @else
        @include('khana::partials.outlet-switcher')
    @endif

    @yield('header-tools')

    <div class="header-spacer"></div>

    <div class="header-actions">
        @if (count($products) > 1)
            <div class="dropdown">
                <button type="button" class="header-btn" data-dropdown-toggle aria-expanded="false"
                        aria-label="Switch product" title="Switch product">
                    <x-khana::icon name="grid" :size="18" />
                </button>

                <div class="dropdown-menu" style="width:280px">
                    <div class="dropdown-header">
                        <strong style="font-size:13.5px">Restro One</strong>
                        <span class="text-xs text-muted" style="display:block">Same sign-in, same outlet.</span>
                    </div>

                    @foreach ($products as $link)
                        @if ($link['url'])
                            <a class="dropdown-item {{ $link['current'] ? 'is-active' : '' }}" href="{{ $link['url'] }}"
                               @if ($link['current']) aria-current="page" @endif>
                        @else
                            <span class="dropdown-item" aria-disabled="true" style="opacity:.55;cursor:default">
                        @endif
                                <x-khana::icon :name="match ($link['key']) { 'crm' => 'cart', 'erp' => 'package', default => 'building' }" :size="16" />
                                <span style="min-width:0">
                                    {{ $link['label'] }}
                                    <span class="text-xs text-muted" style="display:block">{{ $link['hint'] }}</span>
                                </span>
                        @if ($link['url'])
                            </a>
                        @else
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <button type="button" class="header-btn" data-theme-toggle aria-pressed="false" aria-label="Toggle dark mode">
            <span data-icon-moon><x-khana::icon name="moon" :size="18" /></span>
            <span data-icon-sun style="display:none"><x-khana::icon name="sun" :size="18" /></span>
        </button>

        @stack('header-actions')

        @if ($user)
            <div class="dropdown">
                <button type="button" class="header-user" data-dropdown-toggle aria-expanded="false">
                    <span class="avatar" aria-hidden="true">{{ Ui::initials($user->name) }}</span>
                    <span class="header-user-meta">
                        <strong>{{ $user->name }}</strong>
                        <span>@yield('user-role', Ui::productName())</span>
                    </span>
                </button>

                <div class="dropdown-menu">
                    <div class="dropdown-header" style="display:flex;align-items:center;gap:10px">
                        <span class="avatar" aria-hidden="true">{{ Ui::initials($user->name) }}</span>
                        <span style="min-width:0">
                            <strong style="display:block;font-size:13.5px;color:var(--heading)">{{ $user->name }}</strong>
                            <span class="text-xs text-muted">{{ $user->email ?? $user->mobile ?? '' }}</span>
                        </span>
                    </div>

                    @if ($profile)
                        <a class="dropdown-item" href="{{ $profile }}">
                            <x-khana::icon name="user" :size="16" /> Your profile
                        </a>
                    @endif

                    @stack('user-menu')

                    @if (Route::has($logout))
                        <div class="dropdown-divider"></div>

                        <form method="POST" action="{{ route($logout) }}">
                            @csrf
                            <button type="submit" class="dropdown-item is-danger">
                                <x-khana::icon name="logout" :size="16" /> Sign out
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</header>
