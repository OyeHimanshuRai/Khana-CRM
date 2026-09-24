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
                <th>Title</th>
                <th>Post ID</th>
                <th class="num">Order</th>
                <th>Status</th>
                <th>Created</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($posts as $post)
                @php $image = $post->imageUrl(); @endphp
                <tr>
                    <td>
                        <span class="slider-thumb">
                            @if ($image)
                                <img src="{{ $image }}" alt="{{ $post->title }}">
                            @else
                                <x-icon name="grid" :size="16" />
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.instagram.show', $post) }}"
                               data-modal="{{ route('admin.instagram.show', $post) }}"
                               data-modal-title="{{ $post->title }}"
                               data-modal-sub="Instagram post"
                               data-modal-size="lg">{{ $post->title }}</a>
                        </strong>
                        @if ($post->description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($post->description, 80) }}
                            </span>
                        @endif
                    </td>

                    <td>
                        @if ($post->shortcode())
                            <span class="list-ref">{{ $post->shortcode() }}</span>
                        @else
                            <span class="text-xs text-muted">—</span>
                        @endif
                    </td>

                    <td class="num text-muted text-sm">{{ $post->sort_order }}</td>

                    <td>
                        @allows('content.instagram.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.instagram.status', $post) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $post->is_active ? 'is-on' : '' }}"
                                        title="{{ $post->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $post->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $post->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $post->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $post->created_at?->format('d M Y') }}
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.instagram.show', $post) }}"
                               data-modal="{{ route('admin.instagram.show', $post) }}"
                               data-modal-title="{{ $post->title }}"
                               data-modal-sub="Instagram post"
                               data-modal-size="lg"
                               aria-label="View {{ $post->title }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.instagram.edit')
                                <a class="btn btn-icon" href="{{ route('admin.instagram.edit', $post) }}"
                                   data-modal="{{ route('admin.instagram.edit', $post) }}"
                                   data-modal-title="Edit Post"
                                   data-modal-sub="{{ $post->title }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $post->title }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('content.instagram.delete')
                                <form method="POST" action="{{ route('admin.instagram.destroy', $post) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $post->title }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $post->title }}">
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
                            <h3>No posts found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$posts" :per-page="$perPage" :page-sizes="$pageSizes" label="posts" />
