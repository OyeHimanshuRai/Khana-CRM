{{-- Who owes the shop, and how much. --}}

@php
    $rows = $data['rows'];
    $money = fn ($v) => '₹'.number_format((float) $v, 2);
@endphp

<table class="table">
    <thead>
        <tr>
            <th>Customer</th>
            <th>Mobile</th>
            <th>Village</th>
            <th style="text-align:right">Credit limit</th>
            <th style="text-align:right">Owes</th>
            <th>Position</th>
            <th class="col-action">Collect</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>
                    <strong>
                        <a href="{{ route('admin.ledger.index', ['customer' => $row->id]) }}">
                            {{ $row->name }}
                        </a>
                    </strong>
                    <span class="text-xs text-muted" style="display:block">{{ $row->code }}</span>
                </td>
                <td class="text-sm">
                    @if ($row->mobile)
                        <a href="tel:{{ $row->mobile }}">{{ $row->mobile }}</a>
                    @else
                        —
                    @endif
                </td>
                <td class="text-sm">{{ $row->village ?: '—' }}</td>
                <td style="text-align:right" class="text-sm">
                    {{ $row->allow_credit ? $money($row->credit_limit) : 'cash only' }}
                </td>
                <td style="text-align:right">
                    <strong style="color:var(--danger)">{{ $money($row->balance) }}</strong>
                </td>
                <td>
                    @if ($row->isOverLimit())
                        <span class="badge badge-danger">Over limit</span>
                    @elseif ($row->allow_credit)
                        <span class="text-xs text-muted">
                            ₹{{ number_format($row->availableCredit(), 0) }} left
                        </span>
                    @else
                        <span class="text-xs text-muted">—</span>
                    @endif
                </td>
                <td class="col-action">
                    @allows('finance.payments.create')
                        <a class="btn btn-icon"
                           href="{{ route('admin.payments.create', ['customer' => $row->id]) }}"
                           data-modal="{{ route('admin.payments.create', ['customer' => $row->id]) }}"
                           data-modal-title="Record a Payment"
                           data-modal-sub="{{ $row->name }}"
                           data-modal-size="lg"
                           aria-label="Collect from {{ $row->name }}">
                            <x-icon name="wallet" :size="15" />
                        </a>
                    @endallows
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <div class="empty">
                        <x-icon name="user-check" :size="26" />
                        <h3>Nobody owes anything</h3>
                    </div>
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($rows->isNotEmpty())
        <tfoot>
            <tr>
                <th colspan="4">Total outstanding</th>
                <th style="text-align:right">{{ $money($rows->sum('balance')) }}</th>
                <th colspan="2"></th>
            </tr>
        </tfoot>
    @endif
</table>
