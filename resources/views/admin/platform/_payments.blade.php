{{-- Swappable fragment: every subscription payment, newest first. --}}
<div class="table-wrap">
    <table class="table table-list">
        <thead>
            <tr>
                <th>Reference</th>
                <th>Company</th>
                <th>Paid on</th>
                <th>Method</th>
                <th class="pf-num">Amount</th>
                <th class="pf-num">GST</th>
                <th class="pf-num">Net</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td class="text-sm"><strong>{{ $payment->reference }}</strong></td>
                    <td class="text-sm">{{ $payment->tenant?->name ?? '—' }}</td>
                    <td class="text-sm">{{ $payment->paid_at?->format('j M Y') }}</td>
                    <td class="text-sm">{{ $payment->methodLabel() }}</td>
                    <td class="pf-num">₹{{ number_format((float) $payment->amount, 2) }}</td>
                    <td class="pf-num">₹{{ number_format((float) $payment->tax_amount, 2) }}</td>
                    <td class="pf-num">₹{{ number_format((float) $payment->amount - (float) $payment->tax_amount, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <div class="empty">
                            <div class="empty-title">No payments yet</div>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$payments" />
