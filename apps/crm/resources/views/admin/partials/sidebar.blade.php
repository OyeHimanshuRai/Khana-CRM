@php
    /**
     * True when the entry (or one of its children) points at the current page.
     * Falls back to the item's own route name when no `active` pattern is set.
     */
    $isActive = function (array $item): bool {
        $patterns = (array) ($item['active'] ?? ($item['route'] ?? null));

        foreach (array_filter($patterns) as $pattern) {
            if (request()->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    };

    $groupActive = function (array $item) use ($isActive): bool {
        if ($isActive($item)) {
            return true;
        }

        foreach ($item['children'] ?? [] as $child) {
            if ($isActive($child)) {
                return true;
            }
        }

        return false;
    };

    /**
     * Resolve an item to a URL, or null when the module does not exist yet.
     *
     * `params` covers the routes that need one - the report screens are all
     * the same route with a different segment - so the menu can point at
     * them without a controller action each.
     */
    $href = function (array $item) {
        if (empty($item['route']) || ! Route::has($item['route'])) {
            return null;
        }

        return route($item['route'], $item['params'] ?? []);
    };

    /*
     | An entry is visible when two separate things are true, and they answer
     | different questions:
     |
     |   module      does this shop do this kind of business at all?
     |   permission  may this person do it?
     |
     | Both, in that order - checking the cheaper shop-level answer first
     | short-circuits the permission lookup for a whole branch of the menu.
     | An entry that declares neither is platform chrome and always shows.
     |
     | This is presentation only. The same pair is enforced server-side by
     | the `module` and `permission` middleware, because a hidden link is not
     | a closed door.
     */
    /*
     | `unless_can` hides an entry from somebody who holds a *stronger*
     | right, so two audiences can be offered two screens under one subject
     | without either seeing both.
     |
     | The case it exists for is Subscription: a restaurant owner gets their
     | own account, a Super Admin gets the platform's list, and neither
     | wants a sidebar with both. It narrows and never widens - an entry
     | still has to pass its own `can` first - so it cannot be used to grant
     | anybody anything.
     */
    $visible = fn (array $item) => (empty($item['can']) || App\Support\Modules::allows($item['can']))
        && (empty($item['can']) || auth()->user()?->can($item['can']))
        && (empty($item['unless_can']) || ! auth()->user()?->can($item['unless_can']));

    /*
     | Filter the configured menu down to what this user may see. A parent
     | with children survives only if at least one child does, and a heading
     | is dropped once its whole section is filtered away - otherwise a
     | restricted user sees empty section labels.
     */
    $menu = collect(config('menu'))
        ->map(function (array $group) use ($visible) {
            $items = collect($group['items'] ?? [])
                ->map(function (array $item) use ($visible) {
                    if (empty($item['children'])) {
                        return $visible($item) ? $item : null;
                    }

                    $children = collect($item['children'])->filter($visible)->values();

                    if ($children->isEmpty()) {
                        return null;
                    }

                    return [...$item, 'children' => $children->all()];
                })
                ->filter()
                ->values();

            return [...$group, 'items' => $items->all()];
        })
        ->filter(fn (array $group) => ! empty($group['items']))
        ->values();
@endphp

<aside class="sidebar" id="sidebar">
    <div class="sidebar-head">
        {{-- Uploaded Site Logo when there is one, lettermark otherwise. --}}
        <x-brand :href="route('admin.dashboard')" />

        {{-- Drawer dismiss, mobile only. --}}
        <button type="button" class="header-btn sidebar-toggle-mobile" data-sidebar-close
                style="margin-left:auto" aria-label="Close menu">
            <x-icon name="x" :size="19" />
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
                        $hasChildren = ! empty($item['children']);
                        $active = $groupActive($item);
                        $url = $href($item);
                    @endphp

                    <li class="nav-item {{ $hasChildren ? 'has-sub' : '' }}">
                        @if ($hasChildren)
                            <button
                                type="button"
                                class="nav-link {{ $active ? 'is-active' : '' }}"
                                data-submenu-toggle
                                data-label="{{ $item['label'] }}"
                                aria-expanded="false"
                            >
                                <x-icon :name="$item['icon'] ?? 'circle'" />
                                <span class="nav-text">{{ $item['label'] }}</span>
                                @if (! empty($item['badge']))
                                    <span class="nav-badge">{{ $item['badge'] }}</span>
                                @endif
                                <x-icon name="chevron-right" :size="15" class="nav-caret" />
                            </button>

                            <ul class="nav-sub">
                                @foreach ($item['children'] as $child)
                                    @php $childUrl = $href($child); @endphp
                                    <li>
                                        <a
                                            class="nav-link {{ $isActive($child) ? 'is-active' : '' }}"
                                            href="{{ $childUrl ?? '#' }}"
                                            @unless ($childUrl) data-soon data-label="{{ $child['label'] }}" @endunless
                                        >
                                            <span class="nav-text">{{ $child['label'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <a
                                class="nav-link {{ $active ? 'is-active' : '' }}"
                                href="{{ $url ?? '#' }}"
                                data-label="{{ $item['label'] }}"
                                @unless ($url) data-soon @endunless
                                @if ($active) aria-current="page" @endif
                            >
                                <x-icon :name="$item['icon'] ?? 'circle'" />
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

    <div class="sidebar-foot">
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="nav-link" data-label="Sign out">
                <x-icon name="logout" />
                <span class="nav-text">Sign out</span>
            </button>
        </form>
    </div>
</aside>

<div class="sidebar-backdrop" data-sidebar-close aria-hidden="true"></div>
