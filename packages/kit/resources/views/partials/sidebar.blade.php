@php
    /*
     | The app's config('menu'): a list of groups, each
     |
     |   ['heading' => 'Daily'|null, 'items' => [
     |       ['label' => 'Home', 'route' => 'home', 'icon' => 'home',
     |        'active' => 'home*', 'can' => 'permission.name',
     |        'when' => App\Support\SomeCheck::class, 'children' => [...]],
     |   ]]
     |
     | An entry shows when the person holds its `can` and its `when` (an
     | invokable class given the entry) says yes. Hiding a link is only
     | presentation; every route checks the same right on the server.
     */
    $user = auth()->user();

    $isActive = function (array $item): bool {
        foreach (array_filter((array) ($item['active'] ?? ($item['route'] ?? null))) as $pattern) {
            if (request()->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    };

    $href = fn (array $item) => ! empty($item['url'])
        ? $item['url']
        : (! empty($item['route']) && Route::has($item['route']) ? route($item['route'], $item['params'] ?? []) : null);

    $visible = fn (array $item) => (empty($item['can']) || $user?->can($item['can']))
        && (empty($item['when']) || app($item['when'])($item));

    $menu = collect(config('menu', []))
        ->map(function (array $group) use ($visible) {
            $items = collect($group['items'] ?? [])
                ->map(function (array $item) use ($visible) {
                    if (! $visible($item)) {
                        return null;
                    }

                    if (empty($item['children'])) {
                        return $item;
                    }

                    $children = collect($item['children'])->filter($visible)->values();

                    return $children->isEmpty() ? null : [...$item, 'children' => $children->all()];
                })
                ->filter()
                ->values();

            return [...$group, 'items' => $items->all()];
        })
        ->filter(fn (array $group) => ! empty($group['items']))
        ->values();

    $home = config('khana.ui.home');
    $homeUrl = is_string($home) && Route::has($home) ? route($home) : url('/');
    $logout = config('khana.ui.logout', 'logout');
@endphp

<aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
        <x-khana::brand :href="$homeUrl" />

        <button type="button" class="header-btn sidebar-toggle-mobile" data-sidebar-close
                style="margin-left:auto" aria-label="Close menu">
            <x-khana::icon name="x" :size="19" />
        </button>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        @foreach ($menu as $group)
            @if (! empty($group['heading']))
                <div class="nav-heading">{{ $group['heading'] }}</div>
            @endif

            <ul class="nav-list">
                @foreach ($group['items'] as $item)
                    @php
                        $children = $item['children'] ?? [];
                        $active = $isActive($item) || collect($children)->contains(fn ($child) => $isActive($child));
                        $url = $href($item);
                    @endphp

                    <li class="nav-item {{ $children ? 'has-sub' : '' }}">
                        @if ($children)
                            <button type="button" class="nav-link {{ $active ? 'is-active' : '' }}"
                                    data-submenu-toggle data-label="{{ $item['label'] }}" aria-expanded="false">
                                <x-khana::icon :name="$item['icon'] ?? 'circle'" />
                                <span class="nav-text">{{ $item['label'] }}</span>
                                <x-khana::icon name="chevron-right" :size="15" class="nav-caret" />
                            </button>

                            <ul class="nav-sub">
                                @foreach ($children as $child)
                                    <li>
                                        <a class="nav-link {{ $isActive($child) ? 'is-active' : '' }}" href="{{ $href($child) ?? '#' }}">
                                            <span class="nav-text">{{ $child['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <a class="nav-link {{ $active ? 'is-active' : '' }}" href="{{ $url ?? '#' }}"
                               data-label="{{ $item['label'] }}" @if ($active) aria-current="page" @endif>
                                <x-khana::icon :name="$item['icon'] ?? 'circle'" />
                                <span class="nav-text">{{ $item['label'] }}</span>
                                @if (! empty($item['badge']))
                                    <span class="nav-badge">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    </nav>

    @if (Route::has($logout))
        <div class="sidebar-foot">
            <form method="POST" action="{{ route($logout) }}">
                @csrf
                <button type="submit" class="nav-link" data-label="Sign out">
                    <x-khana::icon name="logout" />
                    <span class="nav-text">Sign out</span>
                </button>
            </form>
        </div>
    @endif
</aside>

<div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>
