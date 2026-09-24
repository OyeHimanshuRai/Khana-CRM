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
                <th style="width:1%">Image</th>
                <th>Collection</th>
                <th>Slug</th>
                <th class="num">Order</th>
                <th>Media</th>
                <th>Featured</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($collections as $collection)
                @php $thumb = $collection->thumbnailUrl(); @endphp
                <tr>
                    <td>
                        <span class="slider-thumb">
                            @if ($thumb)
                                <img src="{{ $thumb }}" alt="{{ $collection->name }}">
                            @else
                                {{-- A video-only collection has no still to
                                     show without ffmpeg. --}}
                                <x-icon name="grid" :size="16" />
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.collections.show', $collection) }}"
                               data-modal="{{ route('admin.collections.show', $collection) }}"
                               data-modal-title="{{ $collection->name }}"
                               data-modal-sub="Collection details"
                               data-modal-size="lg">{{ $collection->name }}</a>
                        </strong>
                        @if ($collection->short_description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($collection->short_description, 80) }}
                            </span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $collection->slug }}</span></td>

                    <td class="num text-muted text-sm">{{ $collection->sort_order }}</td>

                    <td class="text-sm">
                        <span class="badge {{ $collection->media->isEmpty() ? '' : 'badge-info' }}">
                            {{ $collection->mediaSummary() }}
                        </span>
                    </td>

                    <td>
                        @allows('content.collections.edit')
                            <form method="POST" action="{{ route('admin.collections.featured', $collection) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $collection->is_featured ? 'is-on' : '' }}"
                                        title="{{ $collection->is_featured ? 'Click to unfeature' : 'Click to feature' }}">
                                    <span class="badge-dot"></span>
                                    {{ $collection->is_featured ? 'Featured' : 'No' }}
                                </button>
                            </form>
                        @else
                            <span class="text-xs text-muted">{{ $collection->is_featured ? 'Yes' : 'No' }}</span>
                        @endallows
                    </td>

                    <td>
                        @allows('content.collections.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.collections.status', $collection) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $collection->is_active ? 'is-on' : '' }}"
                                        title="{{ $collection->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $collection->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $collection->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $collection->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.collections.show', $collection) }}"
                               data-modal="{{ route('admin.collections.show', $collection) }}"
                               data-modal-title="{{ $collection->name }}"
                               data-modal-sub="Collection details"
                               data-modal-size="lg"
                               aria-label="View {{ $collection->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.collections.edit')
                                <a class="btn btn-icon" href="{{ route('admin.collections.edit', $collection) }}"
                                   data-modal="{{ route('admin.collections.edit', $collection) }}"
                                   data-modal-title="Edit Collection"
                                   data-modal-sub="{{ $collection->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $collection->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('content.collections.delete')
                                <form method="POST" action="{{ route('admin.collections.destroy', $collection) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $collection->name }}”? Its media is deleted too, and this cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $collection->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No collections found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$collections" :per-page="$perPage" :page-sizes="$pageSizes" label="collections" />
