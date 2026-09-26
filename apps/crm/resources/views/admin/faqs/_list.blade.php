{{--
    Swappable fragment: the table plus its pagination.

    Rendered inside [data-ajax-list-content] on first load, and returned on
    its own for every AJAX filter/search/page change - so every write in this
    module ends with a data-refresh-list that re-fetches exactly this.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Question</th>
                <th>Category</th>
                <th class="num">Order</th>
                <th>Status</th>
                <th>Created</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($faqs as $faq)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.faqs.show', $faq) }}"
                               data-modal="{{ route('admin.faqs.show', $faq) }}"
                               data-modal-title="FAQ"
                               data-modal-sub="{{ Str::limit($faq->question, 60) }}"
                               data-modal-size="lg">{{ $faq->question }}</a>
                        </strong>
                        {{-- A hint of the answer, so the list is scannable
                             without opening every row. --}}
                        <span class="text-xs text-muted" style="display:block">
                            {{ Str::limit($faq->answer, 90) }}
                        </span>
                    </td>

                    <td>
                        @if ($faq->category)
                            <span class="badge badge-info">{{ $faq->category }}</span>
                        @else
                            <span class="text-xs text-muted">Uncategorised</span>
                        @endif
                    </td>

                    <td class="num text-muted text-sm">{{ $faq->sort_order }}</td>

                    <td>
                        @allows('content.faqs.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.faqs.status', $faq) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $faq->is_active ? 'is-on' : '' }}"
                                        title="{{ $faq->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $faq->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $faq->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $faq->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $faq->created_at?->format('d M Y') }}
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.faqs.show', $faq) }}"
                               data-modal="{{ route('admin.faqs.show', $faq) }}"
                               data-modal-title="FAQ"
                               data-modal-sub="{{ Str::limit($faq->question, 60) }}"
                               data-modal-size="lg"
                               aria-label="View this FAQ">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.faqs.edit')
                                <a class="btn btn-icon" href="{{ route('admin.faqs.edit', $faq) }}"
                                   data-modal="{{ route('admin.faqs.edit', $faq) }}"
                                   data-modal-title="Edit FAQ"
                                   data-modal-sub="{{ Str::limit($faq->question, 60) }}"
                                   data-modal-size="lg"
                                   aria-label="Edit this FAQ">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('content.faqs.delete')
                                <form method="POST" action="{{ route('admin.faqs.destroy', $faq) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete this FAQ? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete this FAQ">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No FAQs found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$faqs" :per-page="$perPage" :page-sizes="$pageSizes" label="FAQs" />
