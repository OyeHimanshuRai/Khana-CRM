@extends('admin.layouts.app')

@section('title', 'FAQs')

@section('content')
    <x-page-header
        title="FAQs"
        :subtitle="$stats['total'].' question'.($stats['total'] === 1 ? '' : 's')"
        :crumbs="['Content' => null, 'FAQs' => null]"
    >
        <x-slot:actions>
            @allows('content.faqs.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.faqs.create') }}"
                   data-modal="{{ route('admin.faqs.create') }}"
                   data-modal-title="Add FAQ"
                   data-modal-sub="Create a new question and answer"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> Add FAQ
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="help" :size="21" /></div>
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
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="user-x" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Inactive</div>
                <div class="stat-value">{{ number_format($stats['inactive']) }}</div>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-info"><x-icon name="list" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Categories</div>
                <div class="stat-value">{{ number_format($stats['categories']) }}</div>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.faqs.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="faq-search" class="sr-only">Search FAQs</label>
                <input id="faq-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search question, answer or category…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                <label for="f-category" class="sr-only">Category</label>
                <select id="f-category" name="category" data-ajax-filter>
                    <option value="">All categories</option>
                    @foreach ($categories as $name)
                        <option value="{{ $name }}" @selected($category === $name)>{{ $name }}</option>
                    @endforeach
                    <option value="—" @selected($category === '—')>Uncategorised</option>
                </select>

                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    <option value="active" @selected($status === 'active')>Active</option>
                    <option value="inactive" @selected($status === 'inactive')>Inactive</option>
                </select>

                <label for="f-sort" class="sr-only">Sort by</label>
                <select id="f-sort" name="sort" data-ajax-filter>
                    <option value="order" @selected($sort === 'order')>Category &amp; order</option>
                    <option value="question_asc" @selected($sort === 'question_asc')>Question A–Z</option>
                    <option value="question_desc" @selected($sort === 'question_desc')>Question Z–A</option>
                    <option value="newest" @selected($sort === 'newest')>Newest first</option>
                    <option value="oldest" @selected($sort === 'oldest')>Oldest first</option>
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.faqs.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.faqs._list')
        </div>
    </div>
@endsection
