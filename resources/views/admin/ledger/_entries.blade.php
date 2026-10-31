{{-- The account, in statement order. --}}

<div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Entry</th>
                <th>Details</th>
                <th style="text-align:right">Debit</th>
                <th style="text-align:right">Credit</th>
                <th style="text-align:right">Balance</th>
                <th>By</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td class="text-sm" style="white-space:nowrap">
                        {{ $entry->entered_at?->format('d M Y') }}
                        <span class="text-xs text-muted" style="display:block">
                            {{ $entry->entered_at?->format('H:i') }}
                        </span>
                    </td>

                    <td class="text-sm">{{ $entry->typeLabel() }}</td>

                    <td class="text-sm">
                        @if ($entry->reference instanceof App\Models\Invoice)
                            <a href="{{ route('admin.invoices.show', $entry->reference) }}">
                                {{ $entry->description }}
                            </a>
                        @else
                            {{ $entry->description ?: '—' }}
                        @endif
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        {{ (float) $entry->debit > 0
                            ? '₹'.number_format((float) $entry->debit, 2)
                            : '' }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        {{ (float) $entry->credit > 0
                            ? '₹'.number_format((float) $entry->credit, 2)
                            : '' }}
                    </td>

                    <td style="text-align:right;white-space:nowrap">
                        @php $balance = (float) $entry->balance_after; @endphp
                        <strong style="color:var({{ $balance > 0 ? '--danger' : ($balance < 0 ? '--success' : '--muted') }})">
                            ₹{{ number_format(abs($balance), 2) }}{{ $balance < 0 ? ' Cr' : '' }}
                        </strong>
                    </td>

                    <td class="text-sm text-muted">{{ $entry->user_name ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <x-icon name="inbox" :size="26" />
                            <h3>Nothing on this account</h3>
                            <p class="text-sm">
                                No invoices, payments or adjustments in the period selected.
                            </p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$entries" :per-page="$perPage" :page-sizes="$pageSizes" label="entries" />
