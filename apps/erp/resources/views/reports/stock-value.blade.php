@php use App\Support\Units; @endphp

<div class="stat-grid" style="margin-bottom:18px">
    <div class="stat"><div class="stat-body"><div class="stat-label">Stock on hand, at cost</div><div class="stat-value">&#8377;{{ Units::rupees($data['total']) }}</div></div></div>
    @if ($data['in_transit'] > 0)
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">On its way to another outlet</div>
                <div class="stat-value">&#8377;{{ Units::rupees($data['in_transit']) }}</div>
                <div class="text-xs text-muted">sent, not yet received - <a href="{{ route('transfers.index') }}">see transfers</a></div>
            </div>
        </div>
    @endif
</div>

@forelse ($data['categories'] as $category)
    <div class="card" style="margin-bottom:18px">
        <div class="card-header" style="display:flex;justify-content:space-between">
            <h2 class="card-title">{{ $category['name'] }}</h2>
            <strong>&#8377;{{ Units::rupees($category['value']) }}</strong>
        </div>
        <div class="table-wrap">
            <table class="table">
                <tbody>
                    @foreach ($category['items'] as $row)
                        <tr>
                            <td><a href="{{ route('stock.show', $row['item']->id) }}">{{ $row['item']->name }}</a></td>
                            <td style="text-align:right;{{ $row['qty'] < 0 ? 'color:var(--danger)' : '' }}">{{ $row['item']->show($row['qty']) }}</td>
                            <td style="text-align:right;width:160px">&#8377;{{ Units::rupees($row['value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@empty
    <div class="card"><x-khana::empty icon="package" title="Nothing on the shelf" /></div>
@endforelse
