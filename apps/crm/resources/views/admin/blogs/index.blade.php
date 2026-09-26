@extends('admin.layouts.app')

@section('title', 'Blog')

@section('content')
    <x-page-header
        title="Blog"
        :subtitle="$stats['total'].' post'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Content' => null, 'Blog' => null]"
    >
        <x-slot:actions>
            @allows('content.blogs.create')
                {{-- Opens the blank form in a modal; the href is the no-JS path. --}}
                <a class="btn btn-primary btn-sm" href="{{ route('admin.blogs.create') }}"
                   data-modal="{{ route('admin.blogs.create') }}"
                   data-modal-title="Add Post"
                   data-modal-sub="Write a new blog post"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add Post
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
                <div class="stat-label">Published</div>
                <div class="stat-value">{{ number_format($stats['published']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-warning"><x-icon name="edit" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Drafts</div>
                <div class="stat-value">{{ number_format($stats['draft']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="star" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Featured</div>
                <div class="stat-value">{{ number_format($stats['featured']) }}</div>
            </div>
        </div>
    </div>

    {{--
        data-ajax-list turns the controls below into no-reload filters:
        ajax-list.js collects every [data-ajax-filter], fetches the fragment
        and swaps [data-ajax-list-content]. The <form> wrapper and the plain
        page links remain as the no-JavaScript fallback.
    --}}
    <div class="card" data-ajax-list="{{ route('admin.blogs.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="blog-search" class="sr-only">Search posts</label>
                <input id="blog-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search title, content, author or category…"
                       autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <label for="f-category" class="sr-only">Category</label>
                <select id="f-category" name="category" data-ajax-filter>
                    <option value="">All categories</option>
                    @foreach ($categories as $name)
                        <option value="{{ $name }}" @selected($category === $name)>{{ $name }}</option>
                    @endforeach
                    <option value="—" @selected($category === '—')>Uncategorised</option>
                </select>

                <label for="f-author" class="sr-only">Author</label>
                <select id="f-author" name="author" data-ajax-filter>
                    <option value="">All authors</option>
                    @foreach ($authors as $person)
                        <option value="{{ $person->id }}" @selected((string) $author === (string) $person->id)>
                            {{ $person->name }}
                        </option>
                    @endforeach
                </select>

                <label for="f-featured" class="sr-only">Featured</label>
                <select id="f-featured" name="featured" data-ajax-filter>
                    <option value="">Featured &amp; not</option>
                    <option value="yes" @selected($featured === 'yes')>Featured only</option>
                    <option value="no" @selected($featured === 'no')>Not featured</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                    <option value="published" @selected($sort === 'published')>Publish date</option>
                    <option value="title_asc" @selected($sort === 'title_asc')>Title A–Z</option>
                    <option value="title_desc" @selected($sort === 'title_desc')>Title Z–A</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.blogs.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.blogs._list')
        </div>
    </div>
@endsection
