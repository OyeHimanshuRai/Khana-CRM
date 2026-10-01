@php
    /*
     | The hubs this person may open - see App\Support\Navigation and
     | config/menu.php. A hub's own screens are the tab strip across the top
     | of its pages (admin.partials.hub-tabs), not a second level here.
     */
    $menu = App\Support\Navigation::menu();
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
                        $active = App\Support\Navigation::isActive($item);
                        $url = App\Support\Navigation::href($item);
                    @endphp

                    <li class="nav-item">
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
