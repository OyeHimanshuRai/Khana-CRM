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
                <th style="width:1%">Thumbnail</th>
                <th>Title</th>
                <th>Reel ID</th>
                <th class="num">Order</th>
                <th>Publish date</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($reels as $reel)
                @php $thumb = $reel->thumbnailUrl(); @endphp
                <tr>
                    <td>
                        <span class="slider-thumb">
                            @if ($thumb)
                                <img src="{{ $thumb }}" alt="{{ $reel->title }}">
                            @else
                                <x-icon name="heart" :size="16" />
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.reels.show', $reel) }}"
                               data-modal="{{ route('admin.reels.show', $reel) }}"
                               data-modal-title="{{ $reel->title }}"
                               data-modal-sub="Instagram reel"
                               data-modal-size="lg">{{ $reel->title }}</a>
                        </strong>
                        @if ($reel->description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($reel->description, 80) }}
                            </span>
                        @endif
                    </td>

                    <td>
                        @if ($reel->shortcode())
                            <span class="list-ref">{{ $reel->shortcode() }}</span>
                        @else
                            <span class="text-xs text-muted">—</span>
                        @endif
                    </td>

                    <td class="num text-muted text-sm">{{ $reel->sort_order }}</td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $reel->published_at?->format('d M Y') ?? '—' }}
                    </td>

                    <td>
                        @allows('content.reels.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.reels.status', $reel) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $reel->is_active ? 'is-on' : '' }}"
                                        title="{{ $reel->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $reel->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $reel->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $reel->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.reels.show', $reel) }}"
                               data-modal="{{ route('admin.reels.show', $reel) }}"
                               data-modal-title="{{ $reel->title }}"
                               data-modal-sub="Instagram reel"
                               data-modal-size="lg"
                               aria-label="View {{ $reel->title }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.reels.edit')
                                <a class="btn btn-icon" href="{{ route('admin.reels.edit', $reel) }}"
                                   data-modal="{{ route('admin.reels.edit', $reel) }}"
                                   data-modal-title="Edit Reel"
                                   data-modal-sub="{{ $reel->title }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $reel->title }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('content.reels.delete')
                                <form method="POST" action="{{ route('admin.reels.destroy', $reel) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $reel->title }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $reel->title }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No reels found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$reels" :per-page="$perPage" :page-sizes="$pageSizes" label="reels" />
