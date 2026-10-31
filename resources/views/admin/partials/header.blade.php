@php
    $user = auth()->user();

    /*
     | The real role, not a hardcoded label - a Manager signing in should
     | not be told they are an Administrator. Several roles read as
     | "Manager +2" rather than wrapping the chip.
     */
    $roles = $user->roles->pluck('name');
    $roleLabel = match (true) {
        $roles->isEmpty() => 'No role assigned',
        $roles->count() === 1 => $roles->first(),
        default => $roles->first().' +'.($roles->count() - 1),
    };
@endphp

<header class="header">
    {{-- One control, two behaviours: rail toggle on desktop, drawer on mobile. --}}
    <button type="button" class="header-btn sidebar-toggle-desktop" data-sidebar-toggle
            aria-label="Collapse sidebar" title="Collapse sidebar">
        <x-icon name="panel-left" :size="19" />
    </button>

    <button type="button" class="header-btn sidebar-toggle-mobile" data-sidebar-toggle
            aria-label="Open menu" aria-controls="sidebar">
        <x-icon name="menu" :size="20" />
    </button>

    {{-- Company first, then branch: the outer choice narrows the inner one,
         and CurrentTenant::set() clears the branch when the company changes. --}}
    @include('admin.partials.tenant-switcher')
    @include('admin.partials.shop-switcher')

    <div class="header-search">
        <x-icon name="search" :size="16" />
        <label for="global-search" class="sr-only">Search or jump to a screen</label>
        {{-- Not a text search: the front door of the command menu (command-menu.js). --}}
        <input id="global-search" type="text" readonly data-command-field aria-haspopup="dialog"
               placeholder="Search or jump to…" autocomplete="off">
        <span class="kbd-group header-kbd" data-kbd-hint aria-hidden="true">
            <kbd class="kbd" data-kbd-mod>Ctrl</kbd><kbd class="kbd">K</kbd>
        </span>
    </div>

    <div class="header-spacer"></div>

    <div class="header-actions">
        <button type="button" class="header-btn header-search-mobile" data-command-open
                aria-label="Search or jump to a screen">
            <x-icon name="search" :size="18" />
        </button>

        <button type="button" class="header-btn" data-theme-toggle aria-pressed="false"
                aria-label="Toggle dark mode">
            <span data-icon-moon><x-icon name="moon" :size="18" /></span>
            <span data-icon-sun style="display:none"><x-icon name="sun" :size="18" /></span>
        </button>

        {{--
            The bell (SRS 15).

            The count comes from a view composer so the dot is honest before
            anything is clicked; the list is fetched on first open, so an
            ordinary page load pays for one count and not ten rows.
        --}}
        @if ($firebasePush ?? null)
            {{-- Public web config only - see App\Models\FirebaseSetting. --}}
            <script type="application/json" id="firebase-config">{!! json_encode($firebasePush, JSON_UNESCAPED_SLASHES) !!}</script>
            <script>
                try {
                    window.__firebase = JSON.parse(document.getElementById('firebase-config').textContent);
                } catch (e) { /* the bell still polls */ }
            </script>

            <button type="button" class="header-btn" data-push-enable hidden
                    title="Get notifications on this device" aria-label="Get notifications on this device">
                <x-icon name="zap" :size="18" />
            </button>
        @endif

        {{-- Kept live by alerts-live.js: the count updates, new alerts toast,
             and the dropdown refetches on its next open. --}}
        <div class="dropdown" data-dropdown-fragment="{{ route('admin.alerts.bell') }}"
             data-alert-bell
             data-alert-feed="{{ route('admin.alerts.feed') }}"
             data-alert-latest="{{ $alertLatestId ?? 0 }}">
            <button type="button" class="header-btn" data-dropdown-toggle
                    aria-expanded="false"
                    aria-label="Notifications{{ ($alertCount ?? 0) > 0 ? ' ('.$alertCount.' unread)' : '' }}">
                <x-icon name="bell" :size="18" />
                <span class="dot dot-count" data-alert-count @if (($alertCount ?? 0) === 0) hidden @endif>{{ ($alertCount ?? 0) > 99 ? '99+' : ($alertCount ?? 0) }}</span>
            </button>

            <div class="dropdown-menu" style="width:320px">
                <div class="empty" style="padding:26px 14px">
                    <x-icon name="inbox" :size="26" />
                    <div class="text-sm">Loading…</div>
                </div>
            </div>
        </div>

        <div class="dropdown">
            <button type="button" class="header-user" data-dropdown-toggle aria-expanded="false">
                <x-avatar :user="$user" live />
                <span class="header-user-meta">
                    <strong>{{ $user->name }}</strong>
                    <span title="{{ $roles->implode(', ') }}">{{ $roleLabel }}</span>
                </span>
            </button>

            <div class="dropdown-menu">
                {{-- Mirrors the chip above: picture, name, then the detail. --}}
                <div class="dropdown-header" style="display:flex;align-items:center;gap:10px">
                    <x-avatar :user="$user" live />
                    <span style="min-width:0">
                        <strong style="display:block;font-size:13.5px;color:var(--heading)">{{ $user->name }}</strong>
                        <span class="text-xs text-muted">{{ $user->email }}</span>
                    </span>
                </div>

                {{-- Opens in a modal; the href is the no-JavaScript path. --}}
                <a class="dropdown-item" href="{{ route('admin.profile.edit') }}"
                   data-modal="{{ route('admin.profile.edit') }}"
                   data-modal-title="Your Profile"
                   data-modal-sub="{{ $user->email }}"
                   data-modal-size="lg">
                    <x-icon name="user" :size="16" /> Profile
                </a>

                <a class="dropdown-item" href="{{ route('admin.profile.notifications') }}">
                    <x-icon name="bell" :size="16" /> Notifications &amp; sound
                </a>

                @allows('settings.users.view')
                    <a class="dropdown-item" href="{{ route('admin.users.index') }}">
                        <x-icon name="users" :size="16" /> Users
                    </a>
                @endallows

                @allows('settings.roles.view')
                    <a class="dropdown-item" href="{{ route('admin.roles.index') }}">
                        <x-icon name="settings" :size="16" /> Roles & Permissions
                    </a>
                @endallows

                @allows('settings.activity_logs.view')
                    <a class="dropdown-item" href="{{ route('admin.activity.index') }}">
                        <x-icon name="file" :size="16" /> Activity Logs
                    </a>
                @endallows

                <div class="dropdown-divider"></div>

                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item is-danger">
                        <x-icon name="logout" :size="16" /> Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
