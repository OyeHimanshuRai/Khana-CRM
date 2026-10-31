{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Supplier</th>
                <th>Contact</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>GSTIN</th>
                <th>Terms</th>
                <th style="text-align:right">Payable</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($suppliers as $supplier)
                @php $balance = (float) $supplier->balance; @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.suppliers.show', $supplier) }}"
                               data-modal="{{ route('admin.suppliers.show', $supplier) }}"
                               data-modal-title="{{ $supplier->displayName() }}"
                               data-modal-sub="Supplier details"
                               data-modal-size="lg">{{ $supplier->displayName() }}</a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $supplier->code }}@if ($supplier->company && $supplier->name !== $supplier->company) · {{ $supplier->name }}@endif
                        </span>
                    </td>

                    <td class="text-sm">
                        @if ($supplier->mobile)
                            <a href="tel:{{ $supplier->mobile }}">{{ $supplier->mobile }}</a>
                        @else
                            —
                        @endif
                        @if ($supplier->contact_person)
                            <span class="text-xs text-muted" style="display:block">{{ $supplier->contact_person }}</span>
                        @endif
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $supplier->shop?->name ?? '—' }}</td>
                    @endif

                    <td class="text-sm">{{ $supplier->gstin ?: '—' }}</td>

                    <td class="text-sm">
                        {{ $supplier->credit_days > 0 ? $supplier->credit_days.' days' : 'Immediate' }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($balance > 0)
                            <strong style="color:var(--danger)">₹{{ number_format($balance, 2) }}</strong>
                        @elseif ($balance < 0)
                            {{-- Negative means we have paid ahead. --}}
                            <span style="color:var(--success)">₹{{ number_format(abs($balance), 2) }} adv</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td>
                        @allows('purchasing.suppliers.edit')
                            <form method="POST" action="{{ route('admin.suppliers.status', $supplier) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $supplier->is_active ? 'is-on' : '' }}"
                                        title="{{ $supplier->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $supplier->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $supplier->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $supplier->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.suppliers.show', $supplier) }}"
                               data-modal="{{ route('admin.suppliers.show', $supplier) }}"
                               data-modal-title="{{ $supplier->displayName() }}"
                               data-modal-sub="Supplier details"
                               data-modal-size="lg"
                               aria-label="View {{ $supplier->displayName() }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('purchasing.suppliers.edit')
                                <a class="btn btn-icon" href="{{ route('admin.suppliers.edit', $supplier) }}"
                                   data-modal="{{ route('admin.suppliers.edit', $supplier) }}"
                                   data-modal-title="Edit Supplier"
                                   data-modal-sub="{{ $supplier->displayName() }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $supplier->displayName() }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('purchasing.suppliers.delete')
                                <form method="POST" action="{{ route('admin.suppliers.destroy', $supplier) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Remove “{{ $supplier->displayName() }}”? Their purchase history is kept.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Remove {{ $supplier->displayName() }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 8 : 7 }}">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No suppliers found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$suppliers" :per-page="$perPage" :page-sizes="$pageSizes" label="suppliers" />
