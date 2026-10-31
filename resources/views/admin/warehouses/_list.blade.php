{{--
    Swappable fragment: the table plus its pagination.

    The Shop column only appears in All-shops mode - inside one shop it would
    be the same value on every row.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Name</th>
                <th>Code</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Location</th>
                <th>Stock lines</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($warehouses as $warehouse)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.warehouses.show', $warehouse) }}"
                               data-modal="{{ route('admin.warehouses.show', $warehouse) }}"
                               data-modal-title="{{ $warehouse->name }}"
                               data-modal-sub="Warehouse details">{{ $warehouse->name }}</a>
                        </strong>

                        @if ($warehouse->is_default)
                            <span class="badge badge-brand" style="margin-left:6px">Default</span>
                        @endif
                    </td>

                    <td><span class="list-ref">{{ $warehouse->code }}</span></td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $warehouse->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">
                        {{ $warehouse->city ?: ($warehouse->address ? Str::limit($warehouse->address, 34) : '—') }}
                    </td>

                    <td class="text-sm">{{ number_format($warehouse->stocks_count) }}</td>

                    <td>
                        @allows('inventory.warehouses.edit')
                            <form method="POST" action="{{ route('admin.warehouses.status', $warehouse) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $warehouse->is_active ? 'is-on' : '' }}"
                                        title="{{ $warehouse->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $warehouse->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $warehouse->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $warehouse->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.warehouses.show', $warehouse) }}"
                               data-modal="{{ route('admin.warehouses.show', $warehouse) }}"
                               data-modal-title="{{ $warehouse->name }}"
                               data-modal-sub="Warehouse details"
                               aria-label="View {{ $warehouse->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('inventory.warehouses.edit')
                                @unless ($warehouse->is_default)
                                    <form method="POST" action="{{ route('admin.warehouses.default', $warehouse) }}"
                                          data-ajax data-refresh-list style="display:inline">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-icon"
                                                title="Make this the default"
                                                aria-label="Make {{ $warehouse->name }} the default">
                                            <x-icon name="star" :size="15" />
                                        </button>
                                    </form>
                                @endunless

                                <a class="btn btn-icon" href="{{ route('admin.warehouses.edit', $warehouse) }}"
                                   data-modal="{{ route('admin.warehouses.edit', $warehouse) }}"
                                   data-modal-title="Edit Warehouse"
                                   data-modal-sub="{{ $warehouse->name }}"
                                   aria-label="Edit {{ $warehouse->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.warehouses.delete')
                                <form method="POST" action="{{ route('admin.warehouses.destroy', $warehouse) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $warehouse->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $warehouse->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 7 : 6 }}">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No warehouses found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$warehouses" :per-page="$perPage" :page-sizes="$pageSizes" label="warehouses" />
