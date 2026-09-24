@props([
    /** Page title, e.g. "Testimonials". */
    'title',
    /** One line under it. */
    'subtitle' => null,
    /** Route name prefix, e.g. "admin.testimonials". */
    'routeBase',
    /** Permission prefix, e.g. "content.testimonials". */
    'can',
    /** Label on the create button, e.g. "Add testimonial". */
    'createLabel' => 'Add',
    /** Icon for the first stat tile. */
    'icon' => 'globe',
    /** Placeholder for the search box. */
    'placeholder' => 'Search…',
    /** @var array<string, int> */
    'stats',
    'search' => '',
    'status' => '',
])

{{--
    The shell every landing-page content screen shares (§19).

    Five modules — testimonials, outlet types, integrations, screenshots and
    trust numbers — are administered identically: three stat tiles, a search
    box, an active/inactive filter and a modal create button. Written out five
    times that is five copies of the same forty lines, and the copies drift.

    What differs between them is the table of rows, which each module still
    supplies as its own `_list` partial through the default slot. That split is
    the point: the chrome is the same, the content never is.
--}}

    <x-page-header
        :title="$title"
        :subtitle="$subtitle"
        :crumbs="['Content' => null, 'Landing Page' => null, $title => null]"
    >
        <x-slot:actions>
            @allows($can.'.create')
                <a class="btn btn-primary btn-sm" href="{{ route($routeBase.'.create') }}"
                   data-modal="{{ route($routeBase.'.create') }}"
                   data-modal-title="{{ $createLabel }}"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> {{ $createLabel }}
                </a>
            @endallows

            <a class="btn btn-ghost btn-sm" href="{{ route('landing') }}" target="_blank" rel="noopener">
                <x-icon name="globe" :size="15" /> View page
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon :name="$icon" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Showing</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
                <span class="text-xs text-muted">live on the page</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="user-x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Hidden</div>
                <div class="stat-value">{{ number_format($stats['inactive']) }}</div>
                {{-- Switched off is not deleted - see the controller. --}}
                <span class="text-xs text-muted">kept, not shown</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route($routeBase.'.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="lp-search" class="sr-only">Search</label>
                <input id="lp-search" type="search" name="q" value="{{ $search }}"
                       placeholder="{{ $placeholder }}" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="lp-status" class="sr-only">Status</label>
                <select id="lp-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Showing</option>
                    <option value="inactive" @selected($status === 'inactive')>Hidden</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route($routeBase.'.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            {{ $slot }}
        </div>
    </div>
