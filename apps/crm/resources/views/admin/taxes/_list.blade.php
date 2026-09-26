{{-- Swappable fragment: the table plus its pagination. --}}

@php
    // "18" rather than "18.000" - the trailing zeros are noise on a rate.
    $pct = fn ($value) => rtrim(rtrim(number_format((float) $value, 3), '0'), '.').'%';
@endphp

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Slab</th>
                <th>Total</th>
                <th>Intra-state (CGST + SGST)</th>
                <th>Inter-state (IGST)</th>
                <th>Cess</th>
                <th>Products</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($rates as $rate)
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.taxes.show', $rate) }}"
                               data-modal="{{ route('admin.taxes.show', $rate) }}"
                               data-modal-title="{{ $rate->name }}"
                               data-modal-sub="Tax slab details">{{ $rate->name }}</a>
                        </strong>
                        @if ($rate->is_default)
                            <span class="badge badge-brand" style="margin-left:6px">Default</span>
                        @endif
                    </td>

                    <td><strong>{{ $pct($rate->rate) }}</strong></td>

                    <td class="text-sm">{{ $pct($rate->cgst) }} + {{ $pct($rate->sgst) }}</td>

                    <td class="text-sm">{{ $pct($rate->igst) }}</td>

                    <td class="text-sm">{{ (float) $rate->cess > 0 ? $pct($rate->cess) : '—' }}</td>

                    <td class="text-sm">{{ number_format($rate->products_count) }}</td>

                    <td>
                        @allows('finance.taxes.edit')
                            <form method="POST" action="{{ route('admin.taxes.status', $rate) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $rate->is_active ? 'is-on' : '' }}"
                                        title="{{ $rate->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $rate->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $rate->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $rate->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.taxes.show', $rate) }}"
                               data-modal="{{ route('admin.taxes.show', $rate) }}"
                               data-modal-title="{{ $rate->name }}"
                               data-modal-sub="Tax slab details"
                               aria-label="View {{ $rate->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('finance.taxes.edit')
                                <a class="btn btn-icon" href="{{ route('admin.taxes.edit', $rate) }}"
                                   data-modal="{{ route('admin.taxes.edit', $rate) }}"
                                   data-modal-title="Edit Tax Slab"
                                   data-modal-sub="{{ $rate->name }}"
                                   aria-label="Edit {{ $rate->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('finance.taxes.delete')
                                <form method="POST" action="{{ route('admin.taxes.destroy', $rate) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Delete “{{ $rate->name }}”? This cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Delete {{ $rate->name }}">
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
                            <h3>No tax slabs found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$rates" :per-page="$perPage" :page-sizes="$pageSizes" label="slabs" />
