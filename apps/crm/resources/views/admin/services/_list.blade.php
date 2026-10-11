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
                <th>Service</th>
                <th>Slug</th>
                <th class="num">Price</th>
                <th class="num">Order</th>
                <th>Status</th>
                <th>Created</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($services as $service)
                @php $image = $service->imageUrl(); @endphp
                <tr>
                    <td>
                        <span class="cat-thumb">
                            @if ($image)
                                <img src="{{ $image }}" alt="{{ $service->name }}">
                            @else
                                {{-- The chosen icon stands in when there is no
                                     picture, so the column is never empty. --}}
                                <x-icon :name="$service->iconName()" :size="19" />
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.services.show', $service) }}"
                               data-modal="{{ route('admin.services.show', $service) }}"
                               data-modal-title="{{ $service->name }}"
                               data-modal-sub="Service details"
                               data-modal-size="lg">{{ $service->name }}</a>
                        </strong>
                        @if ($service->short_description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($service->short_description, 80) }}
                            </span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $service->slug }}</span></td>

                    <td class="num text-sm" style="white-space:nowrap">
                        {{ $service->formattedPrice() ?? '—' }}
                    </td>

                    <td class="num text-muted text-sm">{{ $service->sort_order }}</td>

                    <td>
                        @allows('content.services.edit')
                            {{-- The badge is the button: one click flips it,
                                 and the list refreshes to match. --}}
                            <form method="POST" action="{{ route('admin.services.status', $service) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $service->is_active ? 'is-on' : '' }}"
                                        title="{{ $service->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $service->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $service->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $service->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="text-muted text-sm" style="white-space:nowrap">
                        {{ $service->created_at?->format('d M Y') }}
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.services.show', $service) }}"
                               data-modal="{{ route('admin.services.show', $service) }}"
                               data-modal-title="{{ $service->name }}"
                               data-modal-sub="Service details"
                               data-modal-size="lg"
                               aria-label="View {{ $service->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('content.services.edit')
                                <a class="btn btn-icon" href="{{ route('admin.services.edit', $service) }}"
                                   data-modal="{{ route('admin.services.edit', $service) }}"
                                   data-modal-title="Edit Service"
                                   data-modal-sub="{{ $service->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $service->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('content.services.delete')
                                <form method="POST" action="{{ route('admin.services.destroy', $service) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $service->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $service->name }}">
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
                            <h3>No services found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$services" :per-page="$perPage" :page-sizes="$pageSizes" label="services" />
