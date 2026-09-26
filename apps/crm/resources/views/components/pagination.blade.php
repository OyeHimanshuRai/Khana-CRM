@props([
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator */
    'paginator',
    /** Currently selected page size; omit to hide the per-page control. */
    'perPage' => null,
    'pageSizes' => [10, 15, 25, 50, 100],
    /** Noun used in the summary line: "… of 42 users". */
    'label' => 'entries',
    /** Page numbers shown either side of the current page. */
    'onEachSide' => 1,
])

@php
    /*
     | Shared pagination footer: entries summary, page-size control and the
     | page links. Used by every listing in the admin.
     |
     | Every control is marked for the AJAX list controller
     | (public/assets/js/ajax-list.js): the page-size select is a filter, and
     | each page link carries data-ajax-page. Inside a [data-ajax-list] the
     | script intercepts them and swaps the table without a reload; outside
     | one they stay ordinary links and a plain <form>, so the markup still
     | works with JavaScript disabled.
     */
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();

    // Build the window: first page, a band around the current page, last page.
    $window = collect([1, $last])
        ->merge(range(max(1, $current - $onEachSide), min($last, $current + $onEachSide)))
        ->filter(fn ($page) => $page >= 1 && $page <= $last)
        ->unique()
        ->sort()
        ->values();

    // Walk the window and insert an ellipsis wherever numbers were skipped.
    $pages = collect();
    $previous = 0;

    foreach ($window as $page) {
        if ($previous && $page - $previous > 1) {
            $pages->push(null);
        }

        $pages->push($page);
        $previous = $page;
    }
@endphp

<div class="list-footer">
    @if ($perPage !== null)
        <form method="GET" class="per-page">
            {{-- Kept so a no-JS submit does not drop the active filters. --}}
            @foreach (request()->except(['per_page', 'page']) as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ is_array($value) ? implode(',', $value) : $value }}">
            @endforeach

            <label for="per-page">Row Per Page</label>
            <select id="per-page" name="per_page" data-ajax-filter onchange="this.form.submit()">
                @foreach ($pageSizes as $size)
                    <option value="{{ $size }}" @selected((int) $perPage === (int) $size)>{{ $size }}</option>
                @endforeach
            </select>
            <span class="text-muted text-xs">{{ $label }}</span>
        </form>
    @endif

    @if ($paginator->total() > 0)
        <div class="pager-summary">
            Showing <strong>{{ $paginator->firstItem() }}</strong>–<strong>{{ $paginator->lastItem() }}</strong>
            of <strong>{{ number_format($paginator->total()) }}</strong> {{ $label }}
        </div>
    @endif

    @if ($paginator->hasPages())
        <nav class="pager" role="navigation" aria-label="Pagination">
            <ul class="pager-list">
                @if ($paginator->onFirstPage())
                    <li><span class="pager-link is-disabled" aria-disabled="true">Prev</span></li>
                @else
                    <li>
                        <a class="pager-link" rel="prev" data-ajax-page="{{ $current - 1 }}"
                           href="{{ $paginator->previousPageUrl() }}">Prev</a>
                    </li>
                @endif

                @foreach ($pages as $page)
                    @if ($page === null)
                        <li><span class="pager-link is-dots" aria-hidden="true">…</span></li>
                    @elseif ($page === $current)
                        <li><span class="pager-link is-current" aria-current="page">{{ $page }}</span></li>
                    @else
                        <li>
                            <a class="pager-link" data-ajax-page="{{ $page }}"
                               href="{{ $paginator->url($page) }}"
                               aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        </li>
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <li>
                        <a class="pager-link" rel="next" data-ajax-page="{{ $current + 1 }}"
                           href="{{ $paginator->nextPageUrl() }}">Next</a>
                    </li>
                @else
                    <li><span class="pager-link is-disabled" aria-disabled="true">Next</span></li>
                @endif
            </ul>
        </nav>
    @endif
</div>
