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
                <th>Name</th>
                <th>Slug</th>
                <th>Status</th>
                <th>Created</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($categories as $category)
                @php $image = $category->imageUrl(); @endphp
                <tr>
                    <td>
                        <span class="cat-thumb">
                            @if ($image)
                                <img src="{{ $image }}" alt="{{ $category->name }}">
                            @else
                                {{ $category->initials() }}
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.categories.show', $category) }}"
                               data-modal="{{ route('admin.categories.show', $category) }}"
                               data-modal-title="{{ $category->name }}"
                               data-modal-sub="Category details">{{ $category->name }}</a>
                        </strong>
                        @if ($category->description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($category->description, 70) }}
                            </span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $category->slug }}</span></td>

                    <td>
                        @allows('inventory.categories.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.categories.status', $category) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $category->is_active ? 'is-on' : '' }}"
                                        title="{{ $category->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $category->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $category->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $category->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $category->created_at?->format('d M Y') }}
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.categories.show', $category) }}"
                               data-modal="{{ route('admin.categories.show', $category) }}"
                               data-modal-title="{{ $category->name }}"
                               data-modal-sub="Category details"
                               aria-label="View {{ $category->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('inventory.categories.edit')
                                <a class="btn btn-icon" href="{{ route('admin.categories.edit', $category) }}"
                                   data-modal="{{ route('admin.categories.edit', $category) }}"
                                   data-modal-title="Edit Category"
                                   data-modal-sub="{{ $category->name }}"
                                   aria-label="Edit {{ $category->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.categories.delete')
                                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $category->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $category->name }}">
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
                            <h3>No categories found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$categories" :per-page="$perPage" :page-sizes="$pageSizes" label="categories" />
