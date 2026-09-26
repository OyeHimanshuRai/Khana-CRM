{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Quantities</th>
                <th>Products</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($units as $unit)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.units.show', $unit) }}"
                               data-modal="{{ route('admin.units.show', $unit) }}"
                               data-modal-title="{{ $unit->code }}"
                               data-modal-sub="Unit details"
                               class="list-ref">{{ $unit->code }}</a>
                        </strong>
                    </td>

                    <td>{{ $unit->name }}</td>

                    <td class="text-sm">
                        @if ($unit->allow_decimal)
                            <span class="badge badge-info">
                                Fractional · {{ $unit->precision }} dp
                            </span>
                            <span class="text-xs text-muted" style="display:block">
                                e.g. {{ $unit->format(2.5) }}
                            </span>
                        @else
                            <span class="badge">Whole numbers</span>
                            <span class="text-xs text-muted" style="display:block">
                                e.g. {{ $unit->format(3) }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm">{{ number_format($unit->products_count) }}</td>

                    <td>
                        @allows('inventory.units.edit')
                            <form method="POST" action="{{ route('admin.units.status', $unit) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $unit->is_active ? 'is-on' : '' }}"
                                        title="{{ $unit->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $unit->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $unit->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $unit->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.units.show', $unit) }}"
                               data-modal="{{ route('admin.units.show', $unit) }}"
                               data-modal-title="{{ $unit->code }}"
                               data-modal-sub="Unit details"
                               aria-label="View {{ $unit->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('inventory.units.edit')
                                <a class="btn btn-icon" href="{{ route('admin.units.edit', $unit) }}"
                                   data-modal="{{ route('admin.units.edit', $unit) }}"
                                   data-modal-title="Edit Unit"
                                   data-modal-sub="{{ $unit->name }}"
                                   aria-label="Edit {{ $unit->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('inventory.units.delete')
                                <form method="POST" action="{{ route('admin.units.destroy', $unit) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $unit->code }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $unit->name }}">
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
                            <h3>No units found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$units" :per-page="$perPage" :page-sizes="$pageSizes" label="units" />
