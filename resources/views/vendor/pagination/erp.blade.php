{{--
    Pagination markup for this project's design system.

    Laravel's bundled views target Tailwind or Bootstrap, neither of which is
    compiled here, so they would render unstyled. Registered as the default
    in AppServiceProvider.
--}}
@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination">
        <div class="pager-summary">
            Showing <strong>{{ $paginator->firstItem() }}</strong>–<strong>{{ $paginator->lastItem() }}</strong>
            of <strong>{{ $paginator->total() }}</strong>
        </div>

        <ul class="pager-list">
            @if ($paginator->onFirstPage())
                <li><span class="pager-link is-disabled" aria-disabled="true">Previous</span></li>
            @else
                <li>
                    <a class="pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
                </li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pager-link is-dots">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li>
                                <span class="pager-link is-current" aria-current="page">{{ $page }}</span>
                            </li>
                        @else
                            <li><a class="pager-link" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a class="pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a></li>
            @else
                <li><span class="pager-link is-disabled" aria-disabled="true">Next</span></li>
            @endif
        </ul>
    </nav>
@endif
