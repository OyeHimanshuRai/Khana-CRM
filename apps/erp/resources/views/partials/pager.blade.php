{{-- Previous / next under a list. $rows is a LengthAwarePaginator. --}}
@if ($rows->hasPages())
    <div class="card-footer">
        <div class="pager">
            <div class="pager-summary">
                <strong>{{ $rows->firstItem() }}&ndash;{{ $rows->lastItem() }}</strong> of <strong>{{ $rows->total() }}</strong>
            </div>
            <div style="display:flex;gap:8px">
                @if ($rows->onFirstPage())
                    <span class="btn btn-sm btn-ghost" aria-disabled="true" style="opacity:.5">Previous</span>
                @else
                    <a class="btn btn-sm btn-ghost" href="{{ $rows->previousPageUrl() }}" rel="prev">Previous</a>
                @endif

                @if ($rows->hasMorePages())
                    <a class="btn btn-sm btn-ghost" href="{{ $rows->nextPageUrl() }}" rel="next">Next</a>
                @else
                    <span class="btn btn-sm btn-ghost" aria-disabled="true" style="opacity:.5">Next</span>
                @endif
            </div>
        </div>
    </div>
@endif
