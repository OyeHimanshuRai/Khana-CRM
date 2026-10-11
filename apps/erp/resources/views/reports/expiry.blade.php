@php use App\Support\Units; @endphp

<div class="stat-grid" style="margin-bottom:18px">
    <div class="stat"><div class="stat-body"><div class="stat-label">Already expired</div><div class="stat-value" style="color:var(--danger)">&#8377;{{ Units::rupees((int) $data->where('days', '<', 0)->sum('value')) }}</div></div></div>
    <div class="stat"><div class="stat-body"><div class="stat-label">Expires within a week</div><div class="stat-value">&#8377;{{ Units::rupees((int) $data->whereBetween('days', [0, 7])->sum('value')) }}</div></div></div>
</div>

<div class="card">
    @if ($data->isEmpty())
        <x-khana::empty icon="check" title="Nothing is about to go off" text="No batch with stock in it expires in the next 30 days." />
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Item</th><th>Batch</th><th>Expires</th><th style="text-align:right">On hand</th><th style="text-align:right">Worth</th></tr></thead>
                <tbody>
                    @foreach ($data as $row)
                        <tr>
                            <td><a href="{{ route('stock.show', $row['batch']->stock_item_id) }}">{{ $row['batch']->item?->name }}</a></td>
                            <td>{{ $row['batch']->batch_no }}</td>
                            <td>
                                {{ $row['batch']->expiry_date->format('d M Y') }}
                                @if ($row['days'] < 0)
                                    <span class="badge badge-danger">Expired {{ abs($row['days']) }} {{ abs($row['days']) === 1 ? 'day' : 'days' }} ago</span>
                                @elseif ($row['days'] === 0)
                                    <span class="badge badge-danger">Today</span>
                                @elseif ($row['days'] <= 7)
                                    <span class="badge badge-warning">In {{ $row['days'] }} {{ $row['days'] === 1 ? 'day' : 'days' }}</span>
                                @endif
                            </td>
                            <td style="text-align:right">{{ $row['batch']->item?->show($row['qty']) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($row['value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @can('wastage')
            <div class="card-footer text-sm"><a href="{{ route('wastage.create') }}">Write off what has gone off</a></div>
        @endcan
    @endif
</div>
