{{--
    Swappable fragment: the table plus its pagination.

    The Balance column is the one people scan for, so it carries the tone:
    red once anything is owed, and a separate badge once the credit limit is
    actually breached - "owes money" and "should not be sold to on credit"
    are different facts.
--}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th style="width:1%"></th>
                <th>Name</th>
                <th>Mobile</th>
                @if ($showsShop)<th>Shop</th>@endif
                <th>Type</th>
                <th>Location</th>
                <th style="text-align:right">Balance</th>
                <th>Credit</th>
                <th>Status</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($customers as $customer)
                @php
                    $photo = $customer->imageUrl();
                    $balance = (float) $customer->balance;
                @endphp
                <tr>
                    <td>
                        <span class="cat-thumb">
                            @if ($photo)
                                <img src="{{ $photo }}" alt="{{ $customer->name }}">
                            @else
                                {{ $customer->initials() }}
                            @endif
                        </span>
                    </td>

                    <td>
                        <strong>
                            <a href="{{ route('admin.customers.show', $customer) }}"
                               data-modal="{{ route('admin.customers.show', $customer) }}"
                               data-modal-title="{{ $customer->name }}"
                               data-modal-sub="Customer details"
                               data-modal-size="lg">{{ $customer->name }}</a>
                        </strong>
                        @if ($customer->code)
                            <span class="text-xs text-muted" style="display:block">{{ $customer->code }}</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        @if ($customer->mobile)
                            <a href="tel:{{ $customer->mobile }}">{{ $customer->mobile }}</a>
                        @else
                            —
                        @endif
                    </td>

                    @if ($showsShop)
                        <td class="text-sm">{{ $customer->shop?->name ?? '—' }}</td>
                    @endif

                    <td><span class="badge">{{ $customer->typeLabel() }}</span></td>

                    <td class="text-sm">
                        {{ collect([$customer->village, $customer->district])->filter()->implode(', ') ?: '—' }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @if ($balance > 0)
                            <strong style="color:var(--danger)">₹{{ number_format($balance, 2) }}</strong>
                        @elseif ($balance < 0)
                            {{-- Negative means the shop holds their money. --}}
                            <span style="color:var(--success)">₹{{ number_format(abs($balance), 2) }} adv</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>

                    <td class="text-sm">
                        @if (! $customer->allow_credit)
                            <span class="text-muted">Cash only</span>
                        @elseif ($customer->isOverLimit())
                            <span class="badge badge-danger">Over limit</span>
                        @else
                            <span class="text-xs text-muted">
                                ₹{{ number_format($customer->availableCredit(), 0) }} left
                            </span>
                        @endif
                    </td>

                    <td>
                        @allows('crm.customers.edit')
                            <form method="POST" action="{{ route('admin.customers.status', $customer) }}"
                                  data-ajax data-refresh-list style="display:inline">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="status-toggle {{ $customer->is_active ? 'is-on' : '' }}"
                                        title="{{ $customer->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                    <span class="badge-dot"></span>
                                    {{ $customer->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        @else
                            <span class="badge {{ $customer->is_active ? 'badge-success' : 'badge-danger' }}">
                                <span class="badge-dot"></span>
                                {{ $customer->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        @endallows
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon" href="{{ route('admin.customers.show', $customer) }}"
                               data-modal="{{ route('admin.customers.show', $customer) }}"
                               data-modal-title="{{ $customer->name }}"
                               data-modal-sub="Customer details"
                               data-modal-size="lg"
                               aria-label="View {{ $customer->name }}">
                                <x-icon name="search" :size="15" />
                            </a>

                            @allows('crm.customers.edit')
                                <a class="btn btn-icon" href="{{ route('admin.customers.edit', $customer) }}"
                                   data-modal="{{ route('admin.customers.edit', $customer) }}"
                                   data-modal-title="Edit Customer"
                                   data-modal-sub="{{ $customer->name }}"
                                   data-modal-size="lg"
                                   aria-label="Edit {{ $customer->name }}">
                                    <x-icon name="edit" :size="15" />
                                </a>
                            @endallows

                            @allows('crm.customers.delete')
                                <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}"
                                      data-ajax data-refresh-list
                                      onsubmit="return confirm('Remove “{{ $customer->name }}”? Their invoices are kept for audit.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-icon is-danger"
                                            aria-label="Remove {{ $customer->name }}">
                                        <x-icon name="trash" :size="15" />
                                    </button>
                                </form>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showsShop ? 10 : 9 }}">
                        <div class="empty">
                            <x-icon name="inbox" :size="28" />
                            <h3>No customers found</h3>
                            <p class="text-sm">Adjust the search, or add the first one.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$customers" :per-page="$perPage" :page-sizes="$pageSizes" label="customers" />
