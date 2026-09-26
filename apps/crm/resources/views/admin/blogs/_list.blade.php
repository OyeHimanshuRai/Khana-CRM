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
                <th>Author</th>
                <th>Category</th>
                <th>Status</th>
                <th>Publish date</th>
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
                                <x-icon name="file" :size="16" />
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.blogs.show', $post) }}"
                               data-modal="{{ route('admin.blogs.show', $post) }}"
                               data-modal-title="{{ $post->title }}"
                               data-modal-sub="Blog post"
                               data-modal-size="lg">{{ $post->title }}</a>

                            @if ($post->is_featured)
                                <span class="badge badge-brand" style="margin-left:4px">Featured</span>
                            @endif
                        </strong>

                        @if ($post->short_description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($post->short_description, 80) }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm text-muted">{{ $post->authorLabel() }}</td>

                    <td>
                        @if ($post->category)
                            <span class="badge badge-info">{{ $post->category }}</span>
                        @else
                            <span class="text-xs text-muted">Uncategorised</span>
                        @endif
                    </td>

                    <td>
                        <span class="badge {{ $post->statusTone() }}">
                            <span class="badge-dot"></span> {{ $post->statusLabel() }}
                        </span>
                        @if ($post->isScheduled())
                            {{-- Published but future-dated: not live yet. --}}
                            <div class="text-xs text-muted" style="margin-top:2px">Scheduled</div>
                        @endif
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $post->published_at?->format('d M Y') ?? '—' }}
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.blogs.show', $post) }}"
                               data-modal="{{ route('admin.blogs.show', $post) }}"
                               data-modal-title="{{ $post->title }}"
                               data-modal-sub="Blog post"
                               data-modal-size="lg"
                               aria-label="View {{ $post->title }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.blogs.edit')
                                <a class="btn btn-icon" href="{{ route('admin.blogs.edit', $post) }}"
                                   data-modal="{{ route('admin.blogs.edit', $post) }}"
                                   data-modal-title="Edit Post"
                                   data-modal-sub="{{ $post->title }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $post->title }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            <div class="dropdown">
                                <button type="button" class="btn btn-icon" data-dropdown-toggle
                                        aria-expanded="false" aria-label="More actions for {{ $post->title }}">
                                    <x-icon name="more-vertical" :size="15" />
                                </button>

                                <div class="dropdown-menu" style="min-width:210px">
                                    @allows('content.blogs.edit')
                                        {{-- Three states, so each is its own entry rather
                                             than a toggle that has to guess the next one. --}}
                                        @foreach (\App\Models\Blog::STATUSES as $key => $label)
                                            @continue($post->status === $key)
                                            <form method="POST" action="{{ route('admin.blogs.status', $post) }}"
                                                  data-ajax data-refresh-list>
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="status" value="{{ $key }}">
                                                <button type="submit" class="dropdown-item">
                                                    <x-icon :name="match ($key) {
                                                        'published' => 'user-check',
                                                        'inactive' => 'user-x',
                                                        default => 'edit',
                                                    }" :size="15" />
                                                    Set to {{ $label }}
                                                </button>
                                            </form>
                                        @endforeach

                                        <div class="dropdown-divider"></div>

                                        <form method="POST" action="{{ route('admin.blogs.featured', $post) }}"
                                              data-ajax data-refresh-list>
                                            @csrf
                                            @method('PUT')
                                            <button type="submit" class="dropdown-item">
                                                <x-icon name="star" :size="15" />
                                                {{ $post->is_featured ? 'Remove from featured' : 'Mark as featured' }}
                                            </button>
                                        </form>
                                    @endallows

                                    @allows('content.blogs.delete')
                                        <div class="dropdown-divider"></div>

                                        <form method="POST" action="{{ route('admin.blogs.destroy', $post) }}"
                                              data-ajax data-refresh-list
                                              onsubmit="return confirm('Delete “{{ $post->title }}”? This cannot be undone.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="dropdown-item is-danger">
                                                <x-icon name="trash" :size="15" /> Delete post
                                            </button>
                                        </form>
                                    @endallows
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No posts found</h3>
                            <p class="text-sm">Adjust the search, or write the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$posts" :per-page="$perPage" :page-sizes="$pageSizes" label="posts" />
