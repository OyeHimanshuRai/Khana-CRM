{{--
    One bar chart: a single series, so the title names it and no legend is
    needed. The table under it is the same numbers for anybody who cannot read
    a bar.

    $title   what is being measured
    $rows    [['label' => .., 'value' => ..], ...] oldest first
--}}
@php
    $peak = collect($rows)->max('value');
    $total = collect($rows)->sum('value');
@endphp

<div class="card pf-card">
    <div class="pf-card-head">
        <h2 class="pf-card-title">{{ $title }}</h2>
        <span class="pf-card-total">₹{{ number_format($total, 0) }}</span>
    </div>

    @if ($peak > 0)
        <div class="pf-bars" role="img" aria-label="{{ $title }}, bar chart. The table below has the same figures.">
            @foreach ($rows as $row)
                @php $height = max(0, $row['value']) / $peak * 100; @endphp
                <div class="pf-bar" tabindex="0" data-tip="{{ $row['label'] }}: ₹{{ number_format($row['value'], 0) }}">
                    <div class="pf-bar-fill" style="height: {{ round($height, 1) }}%"></div>
                </div>
            @endforeach
        </div>

        <div class="pf-labels" aria-hidden="true">
            @foreach ($rows as $row)
                <span>{{ $row['label'] }}</span>
            @endforeach
        </div>

        <details class="pf-table">
            <summary>View as table</summary>
            <table>
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row">{{ $row['label'] }}</th>
                        <td>₹{{ number_format($row['value'], 2) }}</td>
                    </tr>
                @endforeach
            </table>
        </details>
    @else
        <div class="pf-empty">Nothing recorded in this period yet.</div>
    @endif
</div>
