@php use App\Support\Units; @endphp

<div class="stat-grid" style="margin-bottom:18px">
    <div class="stat"><div class="stat-body"><div class="stat-label">Owed to suppliers</div><div class="stat-value">&#8377;{{ Units::rupees($data['total']) }}</div></div></div>
    <div class="stat"><div class="stat-body"><div class="stat-label">Of that, overdue</div><div class="stat-value" style="{{ $data['overdue'] > 0 ? 'color:var(--danger)' : '' }}">&#8377;{{ Units::rupees($data['overdue']) }}</div></div></div>
</div>

<div class="card">
    @if ($data['suppliers']->isEmpty())
        <x-khana::empty icon="check" title="Nothing is owed" />
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Supplier</th><th style="text-align:right">Not yet due</th><th style="text-align:right">Up to 30 days late</th><th style="text-align:right">Over 30 days late</th><th style="text-align:right">Paid in advance</th><th style="text-align:right">You owe</th></tr></thead>
                <tbody>
                    @foreach ($data['suppliers'] as $row)
                        <tr>
                            <td><a href="{{ route('suppliers.show', $row['supplier']->id) }}">{{ $row['supplier']->name }}</a></td>
                            <td style="text-align:right">{{ $row['not_due'] ? '₹'.Units::rupees($row['not_due']) : '—' }}</td>
                            <td style="text-align:right">{{ $row['late'] ? '₹'.Units::rupees($row['late']) : '—' }}</td>
                            <td style="text-align:right;{{ $row['very_late'] ? 'color:var(--danger)' : '' }}">{{ $row['very_late'] ? '₹'.Units::rupees($row['very_late']) : '—' }}</td>
                            <td style="text-align:right">{{ $row['advance'] ? '₹'.Units::rupees($row['advance']) : '—' }}</td>
                            <td style="text-align:right"><strong>&#8377;{{ Units::rupees($row['owed']) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
