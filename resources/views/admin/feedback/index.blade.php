@extends('admin.layouts.app')

@section('title', 'Guest Feedback')

@section('content')
    <x-page-header
        title="Guest Feedback"
        :subtitle="$stats['average'] !== null ? $stats['average'].' out of 5 over 30 days' : 'No ratings yet'"
        :crumbs="['Customers' => null, 'Feedback' => null]"
    />

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-icon"><x-icon name="star" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Average</div>
                <div class="stat-value">
                    {{-- Null, not 0.0. "0.0 out of 5" on a restaurant's first
                         week is a lie that reads as a disaster. --}}
                    {{ $stats['average'] !== null ? $stats['average'] : '—' }}
                </div>
                <span class="text-xs text-muted">{{ number_format($stats['count']) }} ratings, 30 days</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                <x-icon name="bell" :size="21" />
            </div>
            <div class="stat-body">
                <div class="stat-label">Needs a reply</div>
                <div class="stat-value">{{ number_format($stats['attention']) }}</div>
                <span class="text-xs text-muted">3 stars or below, unanswered</span>
            </div>
        </div>

        <div class="stat">
            <div class="stat-icon is-success"><x-icon name="mail" :size="21" /></div>
            <div class="stat-body">
                <div class="stat-label">Answered</div>
                <div class="stat-value">{{ number_format($stats['answered']) }}</div>
                <span class="text-xs text-muted">in the last 30 days</span>
            </div>
        </div>
    </div>

    <div class="card" data-ajax-list="{{ route('admin.feedback.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-search">
                <x-icon name="search" :size="15" />
                <label for="fb-search" class="sr-only">Search feedback</label>
                <input id="fb-search" type="search" name="q" value="{{ $search }}"
                       placeholder="Search comments, name or mobile…" autocomplete="off" data-ajax-filter>
            </div>

            <div class="list-filters">
                {{--
                    Ticked by default is deliberate on a screen like this: a
                    feedback list that opens on "all, newest first" is a screen
                    of four-stars, which is pleasant and useless.
                --}}
                <label class="check">
                    <input type="checkbox" name="attention" value="1" @checked($attention) data-ajax-filter>
                    <span>Needs a reply</span>
                </label>

                <label for="f-rating" class="sr-only">Rating</label>
                <select id="f-rating" name="rating" data-ajax-filter>
                    <option value="">Any rating</option>
                    @foreach ([5, 4, 3, 2, 1] as $value)
                        <option value="{{ $value }}" @selected($rating === (string) $value)>
                            {{ $value }} star{{ $value === 1 ? '' : 's' }}
                        </option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.feedback.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.feedback._list')
        </div>
    </div>
@endsection
