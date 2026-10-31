{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:1%">Logo</th>
                <th>Name</th>
                <th>Manufacturer</th>
                <th>Products</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($brands as $brand)
                @php $logo = $brand->logoUrl(); @endphp
                <tr>
                    <td>
                        <span class="cat-thumb">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $brand->name }}">
                            @else
                                {{ $brand->initials() }}
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.brands.show', $brand) }}"
                               data-modal="{{ route('admin.brands.show', $brand) }}"
                               data-modal-title="{{ $brand->name }}"
                               data-modal-sub="Brand details">{{ $brand->name }}</a>
                        </strong>
                        @if ($brand->description)
                            <span class="text-xs text-muted" style="display:block">
                                {{ Str::limit($brand->description, 70) }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">{{ $brand->manufacturer ?: '—' }}</td>

                    <td class="text-sm">{{ number_format($brand->products_count) }}</td>

                    <td>
                        @allows('inventory.brands.edit')
                            <form method="POST" action="{{ route('admin.brands.status', $brand) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $brand->is_active ? 'is-on' : '' }}"
                                        title="{{ $brand->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $brand->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $brand->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $brand->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.brands.show', $brand) }}"
                               data-modal="{{ route('admin.brands.show', $brand) }}"
                               data-modal-title="{{ $brand->name }}"
                               data-modal-sub="Brand details"
                               aria-label="View {{ $brand->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('inventory.brands.edit')
                                <a class="btn btn-icon" href="{{ route('admin.brands.edit', $brand) }}"
                                   data-modal="{{ route('admin.brands.edit', $brand) }}"
                                   data-modal-title="Edit Brand"
                                   data-modal-sub="{{ $brand->name }}"
                                   aria-label="Edit {{ $brand->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.brands.delete')
                                <form method="POST" action="{{ route('admin.brands.destroy', $brand) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $brand->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $brand->name }}">
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
                            <h3>No brands found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$brands" :per-page="$perPage" :page-sizes="$pageSizes" label="brands" />
