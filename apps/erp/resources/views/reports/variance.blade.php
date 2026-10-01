@php use App\Support\Units; $missing = (int) $data->where('difference_value', '<', 0)->sum('difference_value'); @endphp

<div class="stat-grid" style="margin-bottom:18px">
    <div class="stat">
        <div class="stat-body">
            <div class="stat-label">Gone with no explanation</div>
            <div class="stat-value" style="{{ $missing < 0 ? 'color:var(--danger)' : '' }}">&#8377;{{ Units::rupees(abs($missing)) }}</div>
            <div class="text-xs text-muted">what the counts found missing, at cost</div>
        </div>
    </div>
</div>

<div class="card">
    @if ($data->isEmpty())
        <x-khana::empty icon="list" title="Nothing moved in this period" />
    @else
        <div class="card-body text-sm text-muted" style="padding-bottom:0">
            "Counts found" is the difference between the books and the shelf. Stock that left without a recipe,
            a wastage note or a transfer to explain it shows up there - over-portioning, spoilage nobody logged, or theft.
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Item</th><th style="text-align:right">Came in</th><th style="text-align:right">Recipes used</th><th style="text-align:right">Wasted</th><th style="text-align:right">Counts found</th><th style="text-align:right">Worth</th></tr></thead>
                <tbody>
                    @foreach ($data as $row)
                        <tr>
                            <td><a href="{{ route('stock.show', $row['item']->id) }}">{{ $row['item']->name }}</a></td>
                            <td style="text-align:right">{{ $row['received'] ? $row['item']->show($row['received']) : '—' }}</td>
                            <td style="text-align:right">{{ $row['used'] ? $row['item']->show($row['used']) : '—' }}</td>
                            <td style="text-align:right">{{ $row['wasted'] ? $row['item']->show($row['wasted']) : '—' }}</td>
                            <td style="text-align:right;{{ $row['difference'] < 0 ? 'color:var(--danger)' : '' }}">
                                @if ($row['difference'] === 0)
                                    <span class="text-muted">—</span>
                                @else
                                    {{ $row['difference'] > 0 ? '+' : '' }}{{ $row['item']->show($row['difference']) }}
                                @endif
                            </td>
                            <td style="text-align:right">{{ $row['difference_value'] ? '₹'.Units::rupees($row['difference_value']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
