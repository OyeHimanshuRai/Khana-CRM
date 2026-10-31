@extends('admin.layouts.app')

@section('title', $meta['label'].' Report')

@section('content')
    @php
        $money = fn ($value) => '₹'.number_format((float) $value, 2);
        $short = fn ($value) => '₹'.number_format((float) $value, 0);
        $qty = fn ($value) => rtrim(rtrim(number_format((float) $value, 3), '0'), '.');
        $pct = fn ($value) => number_format((float) $value, 1).'%';
    @endphp

    <x-page-header
        :title="$meta['label'].' Report'"
        :subtitle="$meta['dated'] ? $range->label() : 'As at '.now()->format('d M Y, H:i')"
        :crumbs="['Insights' => null, 'Reports' => route('admin.reports.index'), $meta['label'] => null]"
    >
        <x-slot:actions>
            @if ($canExport)
                <a class="btn btn-sm"
                   href="{{ route('admin.reports.export', array_merge([$key], request()->query())) }}">
                    <x-icon name="download" :size="15" /> Export CSV
                </a>
            @endif

            {{-- Printing is the browser's job. A server-rendered PDF would be
                 a dependency and a font-embedding problem for something every
                 browser already does from this page. --}}
            <button type="button" class="btn btn-sm" onclick="window.print()">
                <x-icon name="file" :size="15" /> Print
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- ------------------------------------------------------------ range --}}
    <div class="card no-print" style="margin-bottom:16px">
        <div class="card-body">
            <form method="GET" class="settings-grid">
                @if ($meta['dated'])
                    <div class="field">
                        <label for="r-preset">Period</label>
                        <select id="r-preset" name="preset" class="form-control"
                                onchange="this.form.submit()">
                            @foreach ($presets as $value => $label)
                                <option value="{{ $value }}" @selected($range->preset === $value)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="r-from">From</label>
                        <input id="r-from" type="date" name="from" class="form-control"
                               value="{{ $range->from->toDateString() }}">
                    </div>

                    <div class="field">
                        <label for="r-to">To</label>
                        <input id="r-to" type="date" name="to" class="form-control"
                               value="{{ $range->to->toDateString() }}">
                    </div>
                @endif

                @if ($key === 'expiry')
                    <div class="field">
                        <label for="r-days">Within days</label>
                        <input id="r-days" type="number" name="days" class="form-control"
                               value="{{ $days }}" min="0" max="3650">
                        <div class="form-hint">Anything already expired is always included.</div>
                    </div>
                @endif

                <div class="field" style="align-self:end">
                    <button type="submit" class="btn btn-primary">Run report</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ---------------------------------------------------------- summary --}}
    @if (isset($data['summary']) && in_array($key, ['sales', 'profit'], true))
        @php $s = $data['summary']; @endphp

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon is-success"><x-icon name="cart" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Sales</div>
                    <div class="stat-value">{{ $short($s['current']['total']) }}</div>
                    @if ($s['change'] !== null)
                        <span class="stat-trend {{ $s['change'] >= 0 ? 'up' : 'down' }}">
                            <x-icon :name="$s['change'] >= 0 ? 'trend-up' : 'trend-down'" :size="13" />
                            {{ $pct(abs($s['change'])) }} vs the period before
                        </span>
                    @else
                        <span class="text-xs text-muted">{{ $s['current']['invoices'] }} invoice(s)</span>
                    @endif
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon is-info"><x-icon name="file" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Average bill</div>
                    <div class="stat-value">{{ $short($s['average_bill']) }}</div>
                    <span class="text-xs text-muted">over {{ $s['current']['invoices'] }} invoice(s)</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon"><x-icon name="wallet" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Collected</div>
                    <div class="stat-value">{{ $short($s['current']['collected']) }}</div>
                    <span class="text-xs text-muted">
                        {{ $short($s['current']['outstanding']) }} still owed
                    </span>
                </div>
            </div>

            @allows('reports.profit_report.view')
                <div class="stat">
                    <div class="stat-icon" style="background: var(--brand-soft); color: var(--brand)">
                        <x-icon name="trend-up" :size="21" />
                    </div>
                    <div class="stat-body">
                        <div class="stat-label">Gross profit</div>
                        <div class="stat-value">{{ $short($s['current']['profit']) }}</div>
                        <span class="text-xs text-muted">
                            {{ $s['current']['taxable'] > 0
                                ? $pct($s['current']['profit'] / $s['current']['taxable'] * 100).' margin'
                                : 'no sales' }}
                        </span>
                    </div>
                </div>
            @endallows
        </div>
    @endif

    @if (isset($data['summary']) && $key === 'purchases')
        @php $s = $data['summary']; @endphp

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon"><x-icon name="truck" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Consignments</div>
                    <div class="stat-value">{{ number_format($s['receipts']) }}</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat-icon is-info"><x-icon name="package" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Goods value</div>
                    <div class="stat-value">{{ $short($s['goods']) }}</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat-icon"><x-icon name="file" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Tax &amp; charges</div>
                    <div class="stat-value">{{ $short($s['tax'] + $s['charges']) }}</div>
                </div>
            </div>
            <div class="stat">
                <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                    <x-icon name="wallet" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Still to pay</div>
                    <div class="stat-value">{{ $short($s['outstanding']) }}</div>
                </div>
            </div>
        </div>
    @endif

    @if ($key === 'returns')
        @php $s = $data['summary']; @endphp

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                    <x-icon name="trend-down" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Taken back</div>
                    <div class="stat-value">{{ $short($s['sales_value']) }}</div>
                    <span class="text-xs text-muted">
                        {{ number_format($s['sales_returns']) }} sales return(s)
                    </span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon"><x-icon name="wallet" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Refunded</div>
                    <div class="stat-value">{{ $short($s['refunded']) }}</div>
                    <span class="text-xs text-muted">the rest went back on account</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                    <x-icon name="chart" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Return rate</div>
                    <div class="stat-value">{{ $pct($s['return_rate']) }}</div>
                    {{-- A rupee total says nothing about whether it is a lot;
                         share of what went out does. --}}
                    <span class="text-xs text-muted">of what was sold in the period</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon is-info"><x-icon name="truck" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Sent back to suppliers</div>
                    <div class="stat-value">{{ $short($s['purchase_value']) }}</div>
                    <span class="text-xs text-muted">
                        {{ number_format($s['purchase_returns']) }} purchase return(s)
                    </span>
                </div>
            </div>
        </div>
    @endif

    @if ($key === 'finance')
        @php $s = $data['summary']; @endphp

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon is-success"><x-icon name="cart" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Revenue</div>
                    <div class="stat-value">{{ $short($s['revenue']) }}</div>
                    <span class="text-xs text-muted">
                        {{ $short($s['collected']) }} actually collected
                    </span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon" style="background: var(--brand-soft); color: var(--brand)">
                    <x-icon name="trend-up" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Gross profit</div>
                    <div class="stat-value">{{ $short($s['gross']) }}</div>
                    <span class="text-xs text-muted">
                        {{ $s['margin'] === null ? 'no sales' : $pct($s['margin']).' margin' }}
                    </span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                    <x-icon name="file" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Expenses</div>
                    <div class="stat-value">{{ $short($s['expenses']) }}</div>
                    <span class="text-xs text-muted">{{ $short($s['purchases']) }} spent on stock</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon" style="background: var({{ $s['net'] < 0 ? '--danger-soft' : '--success-soft' }}); color: var({{ $s['net'] < 0 ? '--danger' : '--success' }})">
                    <x-icon name="wallet" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Net</div>
                    <div class="stat-value">{{ $short($s['net']) }}</div>
                    <span class="text-xs text-muted">gross profit less expenses</span>
                </div>
            </div>
        </div>
    @endif

    @if ($key === 'transfers')
        @php $s = $data['summary']; @endphp

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon is-info"><x-icon name="truck" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Transfers</div>
                    <div class="stat-value">{{ number_format($s['transfers']) }}</div>
                    <span class="text-xs text-muted">{{ number_format($s['received']) }} booked in</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon"><x-icon name="package" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Stock moved</div>
                    <div class="stat-value">{{ $short($s['value']) }}</div>
                    <span class="text-xs text-muted">{{ number_format($s['quantity'], 3) }} unit(s)</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon" style="background: var(--warning-soft); color: var(--warning)">
                    <x-icon name="clock" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">On the van</div>
                    <div class="stat-value">{{ $short($s['in_transit_value']) }}</div>
                    {{-- In neither warehouse, which is the whole reason a
                         transfer is two steps rather than one. --}}
                    <span class="text-xs text-muted">
                        {{ number_format($s['in_transit']) }} dispatched, not yet received
                    </span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon"><x-icon name="file" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Awaiting dispatch</div>
                    <div class="stat-value">{{ number_format($s['pending']) }}</div>
                    <span class="text-xs text-muted">raised or approved, not sent</span>
                </div>
            </div>
        </div>
    @endif

    @if ($key === 'day-closing')
        @php $s = $data['summary']; @endphp

        <div class="stat-grid">
            <div class="stat">
                <div class="stat-icon is-info"><x-icon name="wallet" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Days counted</div>
                    <div class="stat-value">{{ number_format($s['days']) }}</div>
                    <span class="text-xs text-muted">{{ $short($s['counted']) }} counted in total</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon"><x-icon name="file" :size="21" /></div>
                <div class="stat-body">
                    <div class="stat-label">Expected</div>
                    <div class="stat-value">{{ $short($s['expected']) }}</div>
                    <span class="text-xs text-muted">float plus takings, less payouts</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon" style="background: var(--danger-soft); color: var(--danger)">
                    <x-icon name="trend-down" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Short</div>
                    <div class="stat-value">{{ $short($s['short']) }}</div>
                    {{-- Over and short are never netted here. A day 500 over
                         cancelling a day 500 short would report a balanced
                         week that never happened. --}}
                    <span class="text-xs text-muted">{{ $short($s['over']) }} over, counted apart</span>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon" style="background: var({{ $s['open'] > 0 ? '--warning-soft' : '--success-soft' }}); color: var({{ $s['open'] > 0 ? '--warning' : '--success' }})">
                    <x-icon name="clock" :size="21" />
                </div>
                <div class="stat-body">
                    <div class="stat-label">Still open</div>
                    <div class="stat-value">{{ number_format($s['open']) }}</div>
                    <span class="text-xs text-muted">
                        {{ number_format($s['unapproved']) }} closed but not signed off
                    </span>
                </div>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------- table --}}
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">{{ $meta['label'] }}</div>
                <div class="text-xs text-muted">
                    {{ $meta['blurb'] }}
                    @if (($data['rows'] ?? collect())->count() >= 500)
                        · showing the first 500 rows — export for the full set
                    @endif
                </div>
            </div>
        </div>

        <div class="table-wrap">
            @include('admin.reports.tables.'.$key)
        </div>
    </div>

    @if ($key === 'tax' && ($data['input'] ?? collect())->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Input tax on purchases</div>
                    <div class="text-xs text-muted">
                        Tax paid to suppliers over the same period, for the credit side of the return.
                    </div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rate</th>
                            <th style="text-align:right">Taxable</th>
                            <th style="text-align:right">CGST</th>
                            <th style="text-align:right">SGST</th>
                            <th style="text-align:right">IGST</th>
                            <th style="text-align:right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['input'] as $row)
                            <tr>
                                <td>{{ rtrim(rtrim(number_format((float) $row->tax_rate, 2), '0'), '.') }}%</td>
                                <td style="text-align:right">{{ $money($row->taxable) }}</td>
                                <td style="text-align:right">{{ $money($row->cgst) }}</td>
                                <td style="text-align:right">{{ $money($row->sgst) }}</td>
                                <td style="text-align:right">{{ $money($row->igst) }}</td>
                                <td style="text-align:right">
                                    <strong>{{ $money((float) $row->cgst + (float) $row->sgst + (float) $row->igst) }}</strong>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($key === 'profit' && ($data['products'] ?? collect())->isNotEmpty())
        <div class="card" style="margin-top:16px">
            <div class="card-header">
                <div>
                    <div class="card-title">Where the margin came from</div>
                    <div class="text-xs text-muted">Top 25 products by gross profit contribution</div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align:right">Sold</th>
                            <th style="text-align:right">Revenue</th>
                            <th style="text-align:right">Cost</th>
                            <th style="text-align:right">Profit</th>
                            <th style="text-align:right">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['products'] as $row)
                            @php
                                $profit = (float) $row->revenue - (float) $row->cost;
                                $margin = (float) $row->revenue > 0
                                    ? $profit / (float) $row->revenue * 100
                                    : null;
                            @endphp
                            <tr>
                                <td>
                                    {{ $row->product_name }}
                                    <span class="text-xs text-muted" style="display:block">{{ $row->sku }}</span>
                                </td>
                                <td style="text-align:right">{{ $qty($row->quantity) }}</td>
                                <td style="text-align:right">{{ $money($row->revenue) }}</td>
                                <td style="text-align:right">{{ $money($row->cost) }}</td>
                                <td style="text-align:right">
                                    <strong style="color:var({{ $profit < 0 ? '--danger' : '--success' }})">
                                        {{ $money($profit) }}
                                    </strong>
                                </td>
                                <td style="text-align:right">
                                    {{ $margin === null ? '—' : $pct($margin) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
