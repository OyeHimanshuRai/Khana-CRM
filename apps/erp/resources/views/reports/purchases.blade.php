@php use App\Support\Units; @endphp

<div class="stat-grid" style="margin-bottom:18px">
    <div class="stat"><div class="stat-body"><div class="stat-label">Bought</div><div class="stat-value">&#8377;{{ Units::rupees($data['total']) }}</div><div class="text-xs text-muted">including &#8377;{{ Units::rupees($data['tax']) }} input tax</div></div></div>
</div>

<div class="card" style="margin-bottom:18px">
    <div class="card-header"><h2 class="card-title">By supplier</h2></div>
    @if ($data['suppliers']->isEmpty())
        <x-khana::empty icon="truck" title="Nothing was received in this period" />
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Supplier</th><th style="text-align:right">Deliveries</th><th style="text-align:right">Before tax</th><th style="text-align:right">Tax</th><th style="text-align:right">Total</th></tr></thead>
                <tbody>
                    @foreach ($data['suppliers'] as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td style="text-align:right">{{ $row['deliveries'] }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($row['net']) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($row['tax']) }}</td>
                            <td style="text-align:right"><strong>&#8377;{{ Units::rupees($row['total']) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if ($data['items']->isNotEmpty())
    <div class="card">
        <div class="card-header"><h2 class="card-title">By item</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Item</th><th style="text-align:right">Bought</th><th style="text-align:right">Before tax</th><th style="text-align:right">Tax</th></tr></thead>
                <tbody>
                    @foreach ($data['items'] as $row)
                        <tr>
                            <td><a href="{{ route('stock.show', $row['item']->id) }}">{{ $row['item']->name }}</a></td>
                            <td style="text-align:right">{{ $row['item']->show($row['qty']) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($row['net']) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($row['tax']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
