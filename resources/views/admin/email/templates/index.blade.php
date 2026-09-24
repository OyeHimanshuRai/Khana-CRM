@extends('admin.layouts.app')

@section('title', 'Email Templates')

@section('content')
    <x-page-header
        title="Email Templates"
        :subtitle="number_format($stats['total']).' template'.($stats['total'] === 1 ? '' : 's').' · used '.number_format($stats['used']).' time'.($stats['used'] === 1 ? '' : 's')"
        :crumbs="['Marketing' => null, 'Email Templates' => null]"
    >
        <x-slot:actions>
            @allows('email.templates.create')
                {{-- Opens the blank form in a modal; the href is the no-JS path. --}}
                <a class="btn btn-primary btn-sm" href="{{ route('admin.email.templates.create') }}"
                   data-modal="{{ route('admin.email.templates.create') }}"
                   data-modal-title="New Template"
                   data-modal-sub="Reusable email content"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> New Template
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="file" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Total</div>
                <div class="stat-value">{{ number_format($stats['total']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="user-check" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Active</div>
                <div class="stat-value">{{ number_format($stats['active']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="tag" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Categories</div>
                <div class="stat-value">{{ number_format($stats['categories']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-warning"><x-icon name="zap" :size="21" /></div>
            <div class="stat-body">
                {{-- How often a template has been turned into a campaign;
                     the one number that says which of these earn their keep. --}}
                <div class="stat-label">Campaigns Started</div>
                <div class="stat-value">{{ number_format($stats['used']) }}</div>
            </div>
        </div>
    </div>

    {{--
        data-ajax-list turns the controls below into no-reload filters:
        ajax-list.js collects every [data-ajax-filter], fetches the fragment
        and swaps [data-ajax-list-content]. The <form> wrapper and the plain
        page links remain as the no-JavaScript fallback.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.email.templates.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="tpl-search" class="sr-only">Search templates</label>
                <input id="tpl-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search name, handle, subject or description…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                @if ($categories->isNotEmpty())
                    <label for="f-category" class="sr-only">Category</label>
                    <select id="f-category" name="category" data-ajax-filter>
                        <option value="">Any category</option>
                        @foreach ($categories as $name)
                            <option value="{{ $name }}" @selected($category === $name)>{{ $name }}</option>
                        @endforeach
                        <option value="—" @selected($category === '—')>Uncategorised</option>
                    </select>
                @endif

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="newest" @selected($sort === 'newest')>Recently added</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest added</option>
                    <option value="used" @selected($sort === 'used')>Most used</option>
                    <option value="name_asc" @selected($sort === 'name_asc')>Name A–Z</option>
                    <option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.email.templates.index') }}"
                   data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.email.templates._list')
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        /* Merge-tag shortcuts in the template form. The chips live inside
           markup injected by modal.js, which never runs its own scripts - so
           the handler is delegated from here. Inserts at the caret rather
           than appending, because a tag belongs mid-sentence. */
        document.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-insert-tag]');
            if (!chip) { return; }

            event.preventDefault();

            var scope = chip.closest('form') || document;
            var field = scope.querySelector('[data-tag-into]');
            if (!field) { return; }

            var tag = chip.getAttribute('data-insert-tag');
            var start = field.selectionStart || 0;
            var end = field.selectionEnd || 0;

            field.value = field.value.slice(0, start) + tag + field.value.slice(end);
            field.focus();
            field.setSelectionRange(start + tag.length, start + tag.length);
        });

    </script>
@endpush
