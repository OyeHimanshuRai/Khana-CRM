{{-- Swappable fragment: the table plus its pagination. --}}

<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Mobile</th>
                <th>Village</th>
                <th style="text-align:right">Credit limit</th>
                <th style="text-align:right">Owes</th>
                <th>Position</th>
                <th class="col-action">Actions</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($customers as $customer)
                @php $balance = (float) $customer->balance; @endphp
                <tr>
                    <td>
                        <strong>
                            <a href="{{ route('admin.ledger.index', ['customer' => $customer->id]) }}">
                                {{ $customer->name }}
                            </a>
                        </strong>
                        <span class="text-xs text-muted" style="display:block">
                            {{ $customer->code }} · {{ $customer->typeLabel() }}
                        </span>
                    </td>

                    <td class="text-sm">
                        @if ($customer->mobile)
                            <a href="tel:{{ $customer->mobile }}">{{ $customer->mobile }}</a>
                        @else
                            —
                        @endif
                    </td>

                    <td class="text-sm">{{ $customer->village ?: '—' }}</td>

                    <td style="text-align:right" class="text-sm">
                        {{ $customer->allow_credit
                            ? '₹'.number_format((float) $customer->credit_limit, 2)
                            : 'cash only' }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        <strong style="color:var(--danger)">₹{{ number_format($balance, 2) }}</strong>
                    </td>

                    <td>
                        @if ($customer->isOverLimit())
                            <span class="badge badge-danger">Over limit</span>
                        @elseif ($customer->allow_credit)
                            <span class="text-xs text-muted">
                                ₹{{ number_format($customer->availableCredit(), 0) }} left
                            </span>
                        @else
                            <span class="text-xs text-muted">No credit allowed</span>
                        @endif
                    </td>

                    <td class="col-action">
                        <div class="row-actions">
                            <a class="btn btn-icon"
                               href="{{ route('admin.ledger.index', ['customer' => $customer->id]) }}"
                               aria-label="Ledger for {{ $customer->name }}">
                                <x-icon name="list" :size="15" />
                            </a>

                            @allows('finance.payments.create')
                                <a class="btn btn-icon"
                                   href="{{ route('admin.payments.create', ['customer' => $customer->id]) }}"
                                   data-modal="{{ route('admin.payments.create', ['customer' => $customer->id]) }}"
                                   data-modal-title="Record a Payment"
                                   data-modal-sub="{{ $customer->name }}"
                                   data-modal-size="lg"
                                   aria-label="Collect from {{ $customer->name }}">
                                    <x-icon name="wallet" :size="15" />
                                </a>
                            @endallows

                            @allows('crm.dues.write_off')
                                <a class="btn btn-icon is-danger"
                                   href="{{ route('admin.dues.write-off.form', $customer) }}"
                                   data-modal="{{ route('admin.dues.write-off.form', $customer) }}"
                                   data-modal-title="Write off a balance"
                                   data-modal-sub="{{ $customer->name }}"
                                   aria-label="Write off for {{ $customer->name }}">
                                    <x-icon name="trash" :size="15" />
                                </a>
                            @endallows
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="user-check" :size="28" />
                            <h3>Nobody owes anything</h3>
                            <p class="text-sm">Every account is settled.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$customers" :per-page="$perPage" label="customers" />
