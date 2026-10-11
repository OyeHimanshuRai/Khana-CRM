@php use App\Support\Units; @endphp

<div class="stat-grid" style="margin-bottom:18px">
    <div class="stat"><div class="stat-body"><div class="stat-label">Sold for</div><div class="stat-value">&#8377;{{ Units::rupees($data['revenue']) }}</div><div class="text-xs text-muted">before tax</div></div></div>
    <div class="stat"><div class="stat-body"><div class="stat-label">The food cost</div><div class="stat-value">&#8377;{{ Units::rupees($data['cost']) }}</div></div></div>
    <div class="stat"><div class="stat-body"><div class="stat-label">Food cost</div><div class="stat-value">{{ $data['percent'] !== null ? $data['percent'].'%' : '—' }}</div></div></div>
    <div class="stat"><div class="stat-body"><div class="stat-label">Cooked, then cancelled</div><div class="stat-value">&#8377;{{ Units::rupees($data['wasted']) }}</div><div class="text-xs text-muted">cost money, earned none</div></div></div>
</div>

@if ($data['uncosted'] > 0)
    <div class="alert alert-warning" role="status" style="margin-bottom:18px">
        {{ $data['uncosted'] }} of the dishes sold {{ $data['uncosted'] === 1 ? 'has' : 'have' }} no cost - no recipe, most likely -
        so the real food cost is higher than this. <a href="{{ route('recipes.index') }}">Write the missing recipes</a>.
    </div>
@endif

<div class="card">
    @if ($data['dishes']->isEmpty())
        <x-khana::empty icon="chart" title="Nothing was sold in this period" />
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Dish</th><th style="text-align:right">Sold</th><th style="text-align:right">For</th><th style="text-align:right">Food cost</th><th style="text-align:right">%</th><th style="text-align:right">Left over</th></tr></thead>
                <tbody>
                    @foreach ($data['dishes'] as $dish)
                        <tr>
                            <td>{{ $dish['name'] }}</td>
                            <td style="text-align:right">{{ Units::plain($dish['qty']) }}</td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($dish['net']) }}</td>
                            <td style="text-align:right">{{ $dish['cost'] > 0 ? '₹'.Units::rupees($dish['cost']) : '—' }}</td>
                            <td style="text-align:right">
                                @if ($dish['percent'] !== null)
                                    {{ $dish['percent'] }}%
                                @else
                                    <span class="badge badge-warning">No cost</span>
                                @endif
                            </td>
                            <td style="text-align:right">&#8377;{{ Units::rupees($dish['net'] - $dish['cost']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
