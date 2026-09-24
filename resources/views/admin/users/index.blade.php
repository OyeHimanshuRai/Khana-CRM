@extends('admin.layouts.app')

@section('title', 'User List')

@section('content')
    <x-page-header
        title="User List"
        :subtitle="$stats['total'].' user'.($stats['total'] === 1 ? '' : 's').' registered'"
        :crumbs="['Settings' => null, 'Users' => null]"
    >
        <x-slot:actions>
            @allows('settings.users.export')
                <div class="dropdown">
                    <button type="button" class="btn btn-sm" data-dropdown-toggle aria-expanded="false">
                        <x-icon name="download" :size="15" /> Export
                        <x-icon name="chevron-down" :size="13" />
                    </button>
                    <div class="dropdown-menu" style="min-width:180px">
                        {{-- Carries the active filters so the file matches the screen. --}}
                        <a class="dropdown-item" href="{{ route('admin.users.export', request()->query()) }}"
                           data-export-link>
                            <x-icon name="file" :size="15" /> Export as CSV
                        </a>
                    </div>
                </div>
            @endallows

            @allows('settings.users.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.users.create') }}">
                    <x-icon name="plus" :size="15" /> Add User
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="users" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total Users</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Online Now</div>
                <div class="stat-value">{{ number_format($stats['online']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="panel-left" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Active Sessions</div>
                <div class="stat-value">{{ number_format($stats['sessions']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="user-x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Deactivated</div>
                <div class="stat-value">{{ number_format($stats['inactive']) }}</div>
            </div>
        </div>
    </div>

    {{--
        data-ajax-list turns the controls below into no-reload filters:
        ajax-list.js collects every [data-ajax-filter], fetches the fragment
        and swaps [data-ajax-list-content]. The <form> wrappers and plain page
        links remain as the no-JavaScript fallback.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.users.index') }}" data-bulk-scope>
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="user-search" class="sr-only">Search users</label>
                <input id="user-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name or email…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-role" class="sr-only">Role</label>
                <select id="f-role" name="role" data-ajax-filter>
                    <option value="">All roles</option>
                    @foreach ($roles as $roleName)
                        <option value="{{ $roleName }}" @selected($roleFilter === $roleName)>{{ $roleName }}</option>
                    @endforeach
                </select>

                <label for="f-status" class="sr-only">Account status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <label for="f-presence" class="sr-only">Online status</label>
                <select id="f-presence" name="presence" data-ajax-filter>
                    <option value="">Online &amp; offline</option>
                    <option value="online" @selected($presence === 'online')>Online now</option>
                    <option value="offline" @selected($presence === 'offline')>Offline</option>
                </select>

                <label for="f-access" class="sr-only">Panel access</label>
                <select id="f-access" name="access" data-ajax-filter>
                    <option value="">Any access</option>
                    <option value="allowed" @selected($access === 'allowed')>Panel allowed</option>
                    <option value="blocked" @selected($access === 'blocked')>Panel blocked</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="latest" @selected($sort === 'latest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                    <option value="last_login" @selected($sort === 'last_login')>Last login</option>
                    <option value="last_seen" @selected($sort === 'last_seen')>Last seen</option>
                    <option value="name_asc" @selected($sort === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option>
                </select>

                {{-- Submit button is the no-JS path; AJAX intercepts before it. --}}
                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.users.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.users._list')
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Keep the Export link pointing at whatever the list is currently showing.
        document.addEventListener('ajaxlist:loaded', function (event) {
            var link = document.querySelector('[data-export-link]');
            if (!link) { return; }

            var query = event.detail.url.split('?')[1] || '';
            link.href = @json(route('admin.users.export')) + (query ? '?' + query : '');
        });
    </script>
@endpush
