@extends('admin.layouts.app')

@section('title', 'Dashboard')

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('assets/css/dashboard.css') }}?v={{ filemtime(public_path('assets/css/dashboard.css')) }}"
    >
@endpush

@section('content')
    @php
        /*
         | Every block below is null when the reader is not entitled to it,
         | rather than zeroed - a cashier gets no profit tile at all instead
         | of one that says nothing. See DashboardController::when().
         |
         | The figures themselves are exactly the ones the controller hands
         | over; this file only decides how they are drawn.
         */
        $money = fn (float $value) => '₹'.number_format($value, 0);

        /*
         | Tile figures are compacted - a year's takings written out in full
         | is a number nobody reads at 25px, and it breaks the tile on a
         | phone. The exact rupee stays on the title attribute, so nothing
         | is actually hidden.
         */
        $compact = function (float $value) {
            $abs = abs($value);
            $trim = fn (float $v, int $dp) => rtrim(rtrim(number_format($v, $dp, '.', ''), '0'), '.');

            return match (true) {
                $abs >= 10000000 => '₹'.$trim($value / 10000000, 2).' Cr',
                $abs >= 100000 => '₹'.$trim($value / 100000, 2).' L',
                $abs >= 1000 => '₹'.$trim($value / 1000, 1).'K',
                default => '₹'.number_format($value, 0),
            };
        };

        /* A tile's twelve-point trend, drawn small. Seven here, because seven
           days is what the controller reads. */
        $sparkPoints = function ($values, float $w = 74, float $h = 28) {
            $values = array_values($values);
            $peak = max(1, max($values));
            $step = count($values) > 1 ? $w / (count($values) - 1) : 0;

            return collect($values)
                ->map(fn ($v, $i) => round($i * $step, 2).','.round($h - 3 - ($v / $peak) * ($h - 8), 2))
                ->implode(' ');
        };

        /* Axis ticks land on round numbers rather than on the data's peak. */
        $niceMax = function (float $value) {
            if ($value <= 0) {
                return 1.0;
            }

            $base = 10 ** floor(log10($value));

            foreach ([1, 2, 2.5, 5] as $multiple) {
                if ($value <= $multiple * $base) {
                    return $multiple * $base;
                }
            }

            return 10 * $base;
        };
    @endphp

    <div class="dash">
        {{-- No breadcrumb here: "Home / Dashboard" on the dashboard is a trail
             back to the page you are standing on. --}}
        <x-page-header
            title="Dashboard"
            :subtitle="$shop
                ? $shop->name.' · '.now()->format('l, d M Y')
                : 'All shops · '.now()->format('l, d M Y')"
        >
            <x-slot:actions>
                @allows('pos.terminal.view')
                    <a class="btn btn-primary btn-sm" href="{{ route('admin.pos.terminal') }}">
                        <x-icon name="cart" :size="15" /> Open the counter
                    </a>
                @endallows
            </x-slot:actions>
        </x-page-header>

        {{-- ------------------------------------------------------- alerts --}}
        @if ($alerts->isNotEmpty())
            <div class="dash-alerts">
                @foreach ($alerts as $alert)
                    <a href="{{ $alert['action'] }}" class="dash-alert is-{{ $alert['tone'] }}">
                        <x-icon :name="$alert['icon']" :size="17" />
                        <span class="dash-alert-text">{{ $alert['title'] }}</span>
                        <span class="dash-alert-action">{{ $alert['label'] }} →</span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- ---------------------------------------------------- stat tiles --}}
        @if ($sales || $profit || $payments || $dues)
            <div class="kpi-grid">
                @if ($sales)
                    <div class="kpi" style="--kpi-accent: var(--success); --kpi-soft: var(--success-soft)">
                        <div class="kpi-top">
                            <span class="kpi-chip"><x-icon name="cart" :size="16" /></span>
                            <span class="kpi-label">Sales today</span>
                        </div>

                        <div class="kpi-main">
                            <div class="kpi-value" title="{{ $money($sales['today']) }}">
                                {{ $compact($sales['today']) }}
                            </div>

                            @php $week = $sales['week']->pluck('total')->all(); @endphp

                            <svg class="kpi-spark" width="74" height="28" viewBox="0 0 74 28"
                                 aria-hidden="true" focusable="false">
                                <polyline
                                    points="{{ $sparkPoints($week) }}"
                                    fill="none"
                                    stroke="var(--success)"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </div>

                        @if ($sales['change'] !== null)
                            <span class="kpi-foot {{ $sales['change'] >= 0 ? 'is-up' : 'is-down' }}">
                                <x-icon :name="$sales['change'] >= 0 ? 'trend-up' : 'trend-down'" :size="12" />
                                {{ number_format(abs($sales['change']), 1) }}% vs yesterday
                            </span>
                        @else
                            <span class="kpi-foot">{{ $sales['today_count'] }} invoice(s) today</span>
                        @endif
                    </div>

                    <div class="kpi" style="--kpi-accent: var(--info); --kpi-soft: var(--info-soft)">
                        <div class="kpi-top">
                            <span class="kpi-chip"><x-icon name="calendar" :size="16" /></span>
                            <span class="kpi-label">This month</span>
                        </div>

                        <div class="kpi-main">
                            <div class="kpi-value" title="{{ $money($sales['month']) }}">
                                {{ $compact($sales['month']) }}
                            </div>
                        </div>

                        <span class="kpi-foot">
                            {{ now()->startOfMonth()->format('d M') }} – {{ now()->format('d M') }}
                        </span>
                    </div>

                    <div class="kpi" style="--kpi-accent: var(--brand); --kpi-soft: var(--brand-soft)">
                        <div class="kpi-top">
                            <span class="kpi-chip"><x-icon name="chart" :size="16" /></span>
                            <span class="kpi-label">This year</span>
                        </div>

                        <div class="kpi-main">
                            <div class="kpi-value" title="{{ $money($sales['year']) }}">
                                {{ $compact($sales['year']) }}
                            </div>
                        </div>

                        <span class="kpi-foot">Since {{ now()->startOfYear()->format('d M') }}</span>
                    </div>
                @endif

                @if ($profit)
                    <div class="kpi" style="--kpi-accent: var(--brand); --kpi-soft: var(--brand-soft)">
                        <div class="kpi-top">
                            <span class="kpi-chip"><x-icon name="trend-up" :size="16" /></span>
                            <span class="kpi-label">Gross profit (month)</span>
                        </div>

                        <div class="kpi-main">
                            <div class="kpi-value" title="{{ $money($profit['gross']) }}">
                                {{ $compact($profit['gross']) }}
                            </div>
                        </div>

                        @if ($profit['margin'] !== null)
                            <span class="kpi-foot {{ $profit['margin'] >= 0 ? 'is-up' : 'is-down' }}">
                                <x-icon :name="$profit['margin'] >= 0 ? 'trend-up' : 'trend-down'" :size="12" />
                                {{ number_format($profit['margin'], 1) }}% margin
                            </span>
                        @else
                            <span class="kpi-foot">No sales yet this month</span>
                        @endif
                    </div>
                @endif

                @if ($payments)
                    <div class="kpi" style="--kpi-accent: var(--s3); --kpi-soft: var(--success-soft)">
                        <div class="kpi-top">
                            <span class="kpi-chip"><x-icon name="wallet" :size="16" /></span>
                            <span class="kpi-label">Collected today</span>
                        </div>

                        <div class="kpi-main">
                            <div class="kpi-value" title="{{ $money($payments['today']) }}">
                                {{ $compact($payments['today']) }}
                            </div>
                        </div>

                        @if ($payments['pending'] > 0)
                            <span class="kpi-foot is-warn">
                                <x-icon name="clock" :size="12" />
                                {{ $money($payments['pending']) }} waiting to clear
                            </span>
                        @else
                            <span class="kpi-foot">Cleared payments only</span>
                        @endif
                    </div>
                @endif

                @if ($dues)
                    <div class="kpi" style="--kpi-accent: var(--warning); --kpi-soft: var(--warning-soft)">
                        <div class="kpi-top">
                            <span class="kpi-chip"><x-icon name="clock" :size="16" /></span>
                            <span class="kpi-label">Outstanding</span>
                        </div>

                        <div class="kpi-main">
                            <div class="kpi-value" title="{{ $money($dues['outstanding']) }}">
                                {{ $compact($dues['outstanding']) }}
                            </div>
                        </div>

                        @if ($dues['overdue_count'] > 0)
                            <span class="kpi-foot is-down">
                                <x-icon name="trend-down" :size="12" />
                                {{ $dues['overdue_count'] }} invoice(s) overdue
                            </span>
                        @else
                            <span class="kpi-foot is-up">
                                <x-icon name="user-check" :size="12" /> Nothing overdue
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ------------------------------------------- gauge / flow / trend --}}
        @if ($profit || $kitchen || $sales)
            <div class="dash-row">
                @if ($profit)
                    @php
                        /*
                         | A margin the month has not earned yet is undefined,
                         | not zero - the needle only goes on the dial when
                         | there is something to measure.
                         */
                        $margin = $profit['margin'];
                        $shown = max(0, min(100, $margin ?? 0));

                        // Half a circle of r=96 is pi*r long; the arc is drawn
                        // by dashing that length rather than by trigonometry.
                        $arc = 301.6;
                        $filled = round($arc * ($shown / 100), 1);
                        $tone = ($margin ?? 0) < 0 ? 'var(--danger)' : 'var(--brand)';
                    @endphp

                    <div class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <div class="dash-card-title">Gross margin</div>
                                <div class="dash-card-sub">
                                    {{ now()->startOfMonth()->format('d M') }} – {{ now()->format('d M') }} ·
                                    what is left after what the goods cost
                                </div>
                            </div>
                        </div>

                        <div class="dash-card-body">
                            <div class="gauge">
                                <svg viewBox="0 0 280 150" role="img"
                                     aria-label="Gross margin {{ $margin !== null ? number_format($margin, 1).'%' : 'not available' }}">
                                    <path
                                        d="M 44 130 A 96 96 0 0 1 236 130"
                                        fill="none"
                                        stroke="var(--track)"
                                        stroke-width="20"
                                        stroke-linecap="round"
                                    />

                                    @if ($margin !== null)
                                        <path
                                            d="M 44 130 A 96 96 0 0 1 236 130"
                                            fill="none"
                                            stroke="{{ $tone }}"
                                            stroke-width="20"
                                            stroke-linecap="round"
                                            stroke-dasharray="{{ $filled }} {{ $arc }}"
                                        />
                                    @endif
                                </svg>

                                <div class="gauge-figure">
                                    <div class="gauge-value">
                                        {{ $margin !== null ? number_format($margin, 1).'%' : '—' }}
                                    </div>
                                    <div class="gauge-caption">
                                        {{ $margin !== null ? 'gross margin' : 'no sales yet' }}
                                    </div>
                                </div>

                                <div class="gauge-scale"><span>0%</span><span>100%</span></div>
                            </div>

                            <dl class="gauge-stats">
                                <div class="gauge-stat">
                                    <dt>Revenue</dt>
                                    <dd title="{{ $money($profit['revenue']) }}">{{ $compact($profit['revenue']) }}</dd>
                                </div>
                                <div class="gauge-stat">
                                    <dt>Cost of goods</dt>
                                    <dd title="{{ $money($profit['cost']) }}">{{ $compact($profit['cost']) }}</dd>
                                </div>
                                <div class="gauge-stat">
                                    <dt>Gross profit</dt>
                                    <dd title="{{ $money($profit['gross']) }}">{{ $compact($profit['gross']) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif

                {{--
                    The kitchen, stage by stage (§5), counted in tickets rather
                    than dishes.

                    Deliberately a different number from the one along the top
                    of the KDS: a manager glancing here asks how many tables
                    are waiting, a cook at the pass asks how many dishes they
                    have to make. Both are right, and they are not the same
                    figure.

                    Drawn as bars, not as a funnel: these are counts of what
                    sits in each stage right now, and a funnel would claim a
                    ticket flows through all five, which is not what is
                    counted here.
                --}}
                @if ($kitchen)
                    @php
                        $stages = [
                            ['label' => 'New', 'count' => $kitchen['pending'], 'colour' => 'var(--o1)'],
                            ['label' => 'Accepted', 'count' => $kitchen['confirmed'], 'colour' => 'var(--o2)'],
                            ['label' => 'Preparing', 'count' => $kitchen['preparing'], 'colour' => 'var(--o3)'],
                            ['label' => 'Ready', 'count' => $kitchen['ready'], 'colour' => 'var(--o4)'],
                            ['label' => 'Served', 'count' => $kitchen['served'], 'colour' => 'var(--o5)'],
                        ];

                        $stagePeak = max(1, collect($stages)->max('count'));
                    @endphp

                    <div class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <div class="dash-card-title">The kitchen</div>
                                <div class="dash-card-sub">
                                    Tickets by stage · today ·
                                    {{ number_format($kitchen['working']) }} still being made
                                </div>
                            </div>

                            @allows('kitchen.tickets.view')
                                <a class="btn btn-sm" href="{{ route('admin.kitchen.index') }}">Display</a>
                            @endallows
                        </div>

                        <div class="dash-card-body">
                            <div class="flow">
                                @foreach ($stages as $stage)
                                    <div class="flow-row" style="--flow-color: {{ $stage['colour'] }}">
                                        <span class="flow-name">
                                            <span class="flow-dot"></span>{{ $stage['label'] }}
                                        </span>

                                        <span class="flow-track">
                                            <span
                                                class="flow-bar"
                                                style="width: {{ $stage['count'] > 0 ? max(3, round($stage['count'] / $stagePeak * 100)) : 0 }}%"
                                            ></span>
                                        </span>

                                        <span class="flow-value">{{ number_format($stage['count']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="dash-card-foot">
                            @if ($kitchen['ready'] > 0)
                                <x-icon name="bell" :size="14" />
                                <span>
                                    {{ number_format($kitchen['ready']) }} ticket(s) on the pass,
                                    waiting to be carried out
                                </span>
                            @elseif ($kitchen['cancelled'] > 0)
                                <x-icon name="x" :size="14" />
                                <span>{{ number_format($kitchen['cancelled']) }} cancelled today</span>
                            @else
                                <x-icon name="user-check" :size="14" />
                                <span>Nothing waiting on the pass</span>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($sales)
                    @php
                        $days = $sales['week'];
                        $top = $niceMax((float) $days->max('total'));

                        /*
                         | The plot box inside the 440x240 viewBox, which is
                         | kept near the width the card actually gets: an SVG
                         | scales its type along with everything else, and a
                         | 760-wide box in a 390px card draws the axis at 5px.
                         */
                        $padL = 40; $padR = 12; $padT = 18; $padB = 28;
                        $plotW = 440 - $padL - $padR;
                        $plotH = 240 - $padT - $padB;
                        $baseY = $padT + $plotH;
                        $stepX = $days->count() > 1 ? $plotW / ($days->count() - 1) : 0;

                        $points = $days->values()->map(function ($day, $i) use ($padL, $padT, $plotH, $stepX, $top) {
                            return [
                                'label' => $day['label'],
                                'total' => $day['total'],
                                'x' => round($padL + $i * $stepX, 2),
                                'y' => round($padT + $plotH - ($day['total'] / $top) * $plotH, 2),
                            ];
                        });

                        $line = $points->map(fn ($p) => $p['x'].','.$p['y'])->implode(' ');
                        $last = $points->last();
                    @endphp

                    <div class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <div class="dash-card-title">The last seven days</div>
                                <div class="dash-card-sub">Invoiced value, cancelled bills excluded</div>
                            </div>

                            <span class="legend">
                                <span class="legend-item">
                                    <span class="legend-dot" style="--legend-color: var(--brand)"></span>
                                    Daily sales
                                </span>
                            </span>
                        </div>

                        <div class="dash-card-body">
                            <div class="area-wrap">
                                <svg viewBox="0 0 440 240" style="color: var(--brand)" role="img"
                                     aria-label="Invoiced value for each of the last seven days">
                                    <defs>
                                        <linearGradient id="dash-area" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="currentColor" stop-opacity=".22" />
                                            <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                                        </linearGradient>
                                    </defs>

                                    {{-- Gridlines and their ticks: the values not
                                         directly labelled still have to be readable. --}}
                                    @foreach ([1, .75, .5, .25, 0] as $fraction)
                                        @php $y = round($padT + $plotH - $fraction * $plotH, 2); @endphp

                                        <line class="area-grid" x1="{{ $padL }}" y1="{{ $y }}"
                                              x2="{{ $padL + $plotW }}" y2="{{ $y }}" />

                                        <text class="area-axis" x="{{ $padL - 8 }}" y="{{ $y + 4 }}"
                                              text-anchor="end">{{ $compact($top * $fraction) }}</text>
                                    @endforeach

                                    <path
                                        d="M {{ $points->first()['x'] }} {{ $points->first()['y'] }}
                                           {{ $points->slice(1)->map(fn ($p) => 'L '.$p['x'].' '.$p['y'])->implode(' ') }}
                                           L {{ $last['x'] }} {{ $baseY }}
                                           L {{ $points->first()['x'] }} {{ $baseY }} Z"
                                        fill="url(#dash-area)"
                                    />

                                    <polyline class="area-line" points="{{ $line }}" />

                                    {{-- Today, the one point worth a marker. --}}
                                    <circle class="area-dot" cx="{{ $last['x'] }}" cy="{{ $last['y'] }}" r="4" />

                                    @foreach ($points as $i => $point)
                                        <text class="area-axis" x="{{ $point['x'] }}" y="{{ $baseY + 18 }}"
                                              text-anchor="middle">{{ $point['label'] }}</text>

                                        {{-- Hit target, then the crosshair it
                                             reveals - siblings, in that order. --}}
                                        <rect
                                            class="area-hit"
                                            x="{{ round($point['x'] - $stepX / 2, 2) }}"
                                            y="{{ $padT }}"
                                            width="{{ round(max($stepX, 20), 2) }}"
                                            height="{{ $plotH }}"
                                        >
                                            <title>{{ $point['label'] }}: {{ $money($point['total']) }}</title>
                                        </rect>

                                        <g class="area-hover">
                                            <line x1="{{ $point['x'] }}" y1="{{ $padT }}"
                                                  x2="{{ $point['x'] }}" y2="{{ $baseY }}" />
                                            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="4" />
                                            <text
                                                class="area-axis"
                                                x="{{ $point['x'] }}"
                                                y="{{ $padT - 3 }}"
                                                text-anchor="{{ $i === 0 ? 'start' : ($i === $points->count() - 1 ? 'end' : 'middle') }}"
                                                style="fill: var(--heading); font-weight: 700; font-size: 12px"
                                            >{{ $compact($point['total']) }}</text>
                                        </g>
                                    @endforeach
                                </svg>
                            </div>
                        </div>

                        <div class="dash-card-foot">
                            <x-icon name="calendar" :size="14" />
                            <span>
                                Today: <strong>{{ $money($sales['today']) }}</strong> ·
                                yesterday: {{ $money($sales['yesterday']) }}
                            </span>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ----------------------------------------- money in, money owed --}}
        @if ($payments || $dues || $topProducts)
            <div class="dash-row is-split">
                @if ($payments || $dues)
                    <div class="dash-card">
                        @if ($payments)
                            @php
                                /*
                                 | A fixed slot per method, so the colours do
                                 | not shuffle on a day when nobody paid by
                                 | card - the hue belongs to the method, not
                                 | to its rank in today's takings.
                                 */
                                $methodColour = [
                                    'cash' => 'var(--s1)',
                                    'upi' => 'var(--s2)',
                                    'card' => 'var(--s3)',
                                    'bank' => 'var(--s4)',
                                    'wallet' => 'var(--s5)',
                                    'cheque' => 'var(--s6)',
                                ];

                                $collected = (float) $payments['by_method']->sum();
                            @endphp

                            <div class="dash-card-head">
                                <div>
                                    <div class="dash-card-title">Collected today</div>
                                    <div class="dash-card-sub">Cleared payments, by method</div>
                                </div>

                                @allows('finance.payments.view')
                                    <a class="btn btn-sm" href="{{ route('admin.payments.index') }}">Open</a>
                                @endallows
                            </div>

                            <div class="dash-card-body">
                                <div class="mix">
                                    <div class="mix-head">
                                        <strong>{{ $money($payments['today']) }}</strong>
                                        <span>{{ $payments['by_method']->count() }} method(s)</span>
                                    </div>

                                    @if ($collected > 0)
                                        <div class="mix-bar">
                                            {{-- Only what has value. A zero drawn as a
                                                 sliver is a lie the eye believes
                                                 before it reaches the list. --}}
                                            @foreach ($payments['by_method']->filter(fn ($total) => (float) $total > 0) as $method => $total)
                                                <span
                                                    class="mix-seg"
                                                    style="--seg-color: {{ $methodColour[$method] ?? 'var(--muted)' }};
                                                           flex: {{ (float) $total }} 1 0"
                                                    title="{{ App\Models\Payment::METHODS[$method]['label'] ?? Str::headline($method) }}: {{ $money((float) $total) }}"
                                                ></span>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="mix-list">
                                        @forelse ($payments['by_method'] as $method => $total)
                                            <div class="mix-item">
                                                <span
                                                    class="legend-dot"
                                                    style="--legend-color: {{ $methodColour[$method] ?? 'var(--muted)' }}"
                                                ></span>
                                                <span>
                                                    {{ App\Models\Payment::METHODS[$method]['label'] ?? Str::headline($method) }}
                                                </span>
                                                <b>{{ $money((float) $total) }}</b>
                                            </div>
                                        @empty
                                            <div class="mix-empty">Nothing collected yet today.</div>
                                        @endforelse

                                        @if ($payments['pending'] > 0)
                                            <div class="mix-item">
                                                <span class="legend-dot" style="--legend-color: var(--warning)"></span>
                                                <span>Waiting to clear</span>
                                                <b style="color: var(--warning)">{{ $money($payments['pending']) }}</b>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if ($dues)
                            @php
                                /*
                                 | Overdue and "within 7 days" are the two that
                                 | do not overlap - the query for the second
                                 | starts at today. Due-today sits inside it,
                                 | so it is a line in the list rather than a
                                 | third segment that would count twice.
                                 */
                                $falling = $dues['overdue'] + $dues['upcoming'];
                            @endphp

                            <div class="dash-card-head" @if ($payments) style="border-top: 1px solid var(--border); padding-top: 16px" @endif>
                                <div>
                                    <div class="dash-card-title">Money owed</div>
                                    <div class="dash-card-sub">
                                        {{ number_format($dues['customers']) }} customer(s) carrying a balance
                                    </div>
                                </div>

                                @allows('crm.dues.view')
                                    <a class="btn btn-sm" href="{{ route('admin.dues.index') }}">Open</a>
                                @endallows
                            </div>

                            <div class="dash-card-body">
                                <div class="mix">
                                    @if ($falling > 0)
                                        <div class="mix-bar">
                                            @if ($dues['overdue'] > 0)
                                                <span
                                                    class="mix-seg"
                                                    style="--seg-color: var(--danger); flex: {{ $dues['overdue'] }} 1 0"
                                                    title="Overdue: {{ $money($dues['overdue']) }}"
                                                ></span>
                                            @endif

                                            @if ($dues['upcoming'] > 0)
                                                <span
                                                    class="mix-seg"
                                                    style="--seg-color: var(--s1); flex: {{ $dues['upcoming'] }} 1 0"
                                                    title="Due within 7 days: {{ $money($dues['upcoming']) }}"
                                                ></span>
                                            @endif
                                        </div>
                                    @endif

                                    <dl class="metrics">
                                        <div>
                                            <dt>
                                                <span class="legend-dot" style="--legend-color: var(--danger); display:inline-block; vertical-align:middle; margin-right:7px"></span>
                                                Overdue
                                            </dt>
                                            <dd class="is-bad">
                                                {{ $money($dues['overdue']) }}
                                                <small>({{ $dues['overdue_count'] }})</small>
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>
                                                <span class="legend-dot" style="--legend-color: var(--s1); display:inline-block; vertical-align:middle; margin-right:7px"></span>
                                                Due within 7 days
                                            </dt>
                                            <dd>{{ $money($dues['upcoming']) }}</dd>
                                        </div>
                                        <div>
                                            <dt style="padding-left: 17px">of which, due today</dt>
                                            <dd class="is-warn">{{ $money($dues['due_today']) }}</dd>
                                        </div>
                                        <div class="is-total">
                                            <dt>Total outstanding</dt>
                                            <dd>{{ $money($dues['outstanding']) }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if ($topProducts)
                    @php $bestSeller = max(1, (float) ($topProducts->max('revenue') ?? 0)); @endphp

                    <div class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <div class="dash-card-title">Best sellers this month</div>
                                <div class="dash-card-sub">
                                    By invoiced value · the bar is each line's share of the best seller
                                </div>
                            </div>
                        </div>

                        <div class="dash-card-body is-flush">
                            <table class="dash-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="num">Sold</th>
                                        <th class="num">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($topProducts as $i => $row)
                                        <tr>
                                            <td>
                                                <div class="cell-lead">
                                                    <span
                                                        class="cell-rank"
                                                        @if ($i === 0) style="--rank-soft: var(--brand-soft); --rank-color: var(--brand)" @endif
                                                    >{{ $i + 1 }}</span>

                                                    <span class="cell-name">
                                                        <strong>{{ $row->product_name }}</strong>
                                                        <small>{{ $row->sku }}</small>
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="num">
                                                {{ rtrim(rtrim(number_format((float) $row->quantity, 3), '0'), '.') }}
                                            </td>
                                            <td class="num">
                                                <span class="share">
                                                    <span class="share-track">
                                                        <span
                                                            class="share-fill"
                                                            style="width: {{ max(2, round((float) $row->revenue / $bestSeller * 100)) }}%"
                                                        ></span>
                                                    </span>
                                                    <strong>{{ $money((float) $row->revenue) }}</strong>
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="3">
                                                <div class="dash-empty">
                                                    <x-icon name="package" :size="26" />
                                                    <h3>Nothing sold yet this month</h3>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- -------------------------------------------- the room and the shelf --}}
        @if ($room || $stock || $customers)
            <div class="dash-row">
                @if ($room)
                    <div class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <div class="dash-card-title">The room</div>
                                <div class="dash-card-sub">
                                    {{ number_format($room['total']) }} table(s) ·
                                    {{ number_format($room['seats']) }} seat(s) in service
                                </div>
                            </div>

                            @allows('dining.tables.view')
                                <a class="btn btn-sm" href="{{ route('admin.tables.plan') }}">Floor plan</a>
                            @endallows
                        </div>

                        <div class="dash-card-body">
                            {{-- Billing counts as seated: those guests have not
                                 left, and a figure that said otherwise would
                                 send the next party to their table. --}}
                            <div class="mix-head" style="margin-bottom: 10px">
                                <strong>{{ $room['occupancy'] }}%</strong>
                                <span>occupied right now</span>
                            </div>

                            @php
                                $seated = $room['occupied'] + $room['billing'];
                                $free = max(0, $room['total'] - $seated);
                            @endphp

                            <div class="mix-bar" style="margin-bottom: 14px">
                                @if ($seated > 0)
                                    <span
                                        class="mix-seg"
                                        style="--seg-color: var(--warning); flex: {{ $seated }} 1 0"
                                        title="Seated: {{ number_format($seated) }} table(s)"
                                    ></span>
                                @endif

                                @if ($free > 0)
                                    <span
                                        class="mix-seg"
                                        style="--seg-color: var(--track); flex: {{ $free }} 1 0"
                                        title="Not seated: {{ number_format($free) }} table(s)"
                                    ></span>
                                @endif
                            </div>

                            <dl class="metrics">
                                <div>
                                    <dt>Available</dt>
                                    <dd class="{{ $room['available'] > 0 ? 'is-good' : 'is-muted' }}">
                                        {{ number_format($room['available']) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Occupied</dt>
                                    <dd class="{{ $room['occupied'] > 0 ? 'is-warn' : 'is-muted' }}">
                                        {{ number_format($room['occupied']) }}
                                    </dd>
                                </div>
                                <div><dt>Reserved</dt><dd>{{ number_format($room['reserved']) }}</dd></div>
                                <div>
                                    <dt>Billing</dt>
                                    <dd class="{{ $room['billing'] > 0 ? 'is-brand' : 'is-muted' }}">
                                        {{ number_format($room['billing']) }}
                                    </dd>
                                </div>
                                <div><dt>Cleaning</dt><dd>{{ number_format($room['cleaning']) }}</dd></div>
                            </dl>
                        </div>
                    </div>
                @endif

                @if ($stock)
                    <div class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <div class="dash-card-title">Stock</div>
                                <div class="dash-card-sub">
                                    {{ number_format($stock['lines']) }} line(s) on the shelf
                                </div>
                            </div>

                            @allows('inventory.stock.view')
                                <a class="btn btn-sm" href="{{ route('admin.stock.index') }}">Open</a>
                            @endallows
                        </div>

                        <div class="dash-card-body">
                            <div class="mix-head" style="margin-bottom: 14px">
                                <strong title="{{ $money($stock['value']) }}">{{ $compact($stock['value']) }}</strong>
                                <span>at average cost</span>
                            </div>

                            <dl class="metrics">
                                <div>
                                    <dt>At reorder level</dt>
                                    <dd class="{{ $stock['low'] > 0 ? 'is-warn' : 'is-muted' }}">
                                        {{ number_format($stock['low']) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Out of stock</dt>
                                    <dd class="{{ $stock['out'] > 0 ? 'is-bad' : 'is-muted' }}">
                                        {{ number_format($stock['out']) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Near expiry</dt>
                                    <dd class="{{ $stock['near_expiry'] > 0 ? 'is-warn' : 'is-muted' }}">
                                        {{ number_format($stock['near_expiry']) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt>Expired, still held</dt>
                                    <dd class="{{ $stock['expired'] > 0 ? 'is-bad' : 'is-muted' }}">
                                        {{ number_format($stock['expired']) }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif

                @if ($customers)
                    <div class="dash-card">
                        <div class="dash-card-head">
                            <div>
                                <div class="dash-card-title">Customers</div>
                                <div class="dash-card-sub">Active accounts</div>
                            </div>

                            @allows('crm.customers.view')
                                <a class="btn btn-sm" href="{{ route('admin.customers.index') }}">Open</a>
                            @endallows
                        </div>

                        <div class="dash-card-body">
                            <div class="mix-head" style="margin-bottom: 14px">
                                <strong>{{ number_format($customers['total']) }}</strong>
                                <span>on the books</span>
                            </div>

                            <dl class="metrics">
                                <div>
                                    <dt>New this month</dt>
                                    <dd class="is-good">{{ number_format($customers['new_this_month']) }}</dd>
                                </div>
                                {{-- "Active" means bought something in the last
                                     90 days, which is what a shop means by it -
                                     not that the row is not disabled. --}}
                                <div>
                                    <dt>Bought in 90 days</dt>
                                    <dd>{{ number_format($customers['buying']) }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- ---------------------------------------------------- the invoices --}}
        @if ($recentInvoices)
            <div class="dash-row">
                <div class="dash-card">
                    <div class="dash-card-head">
                        <div>
                            <div class="dash-card-title">Latest invoices</div>
                            <div class="dash-card-sub">The last eight, newest first</div>
                        </div>

                        @allows('sales.invoices.view')
                            <a class="btn btn-sm" href="{{ route('admin.invoices.index') }}">View all</a>
                        @endallows
                    </div>

                    <div class="dash-card-body is-flush">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Status</th>
                                    <th class="num">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($recentInvoices as $invoice)
                                    <tr>
                                        <td>
                                            <div class="cell-lead">
                                                <span class="cell-rank"><x-icon name="file" :size="13" /></span>

                                                <span class="cell-name">
                                                    <a href="{{ route('admin.invoices.show', $invoice) }}">
                                                        {{ $invoice->number }}
                                                    </a>
                                                    <small>{{ $invoice->invoiced_at?->diffForHumans() }}</small>
                                                </span>
                                            </div>
                                        </td>
                                        <td>{{ $invoice->billedTo() }}</td>
                                        <td>
                                            <span class="pill {{ $invoice->statusTone() ? 'is-'.$invoice->statusTone() : '' }}">
                                                {{ $invoice->statusLabel() }}
                                            </span>
                                        </td>
                                        <td class="num">
                                            <strong>₹{{ number_format((float) $invoice->grand_total, 2) }}</strong>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4">
                                            <div class="dash-empty">
                                                <x-icon name="file" :size="26" />
                                                <h3>No invoices yet</h3>
                                                <p>Ring up the first sale at the counter.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        @if (! $sales && ! $stock && ! $dues && ! $payments && ! $customers)
            {{-- Somebody with a very narrow role. Better an honest empty screen
                 than a wall of tiles reading zero. --}}
            <div class="dash-card">
                <div class="dash-empty" style="padding: 44px 20px">
                    <x-icon name="grid" :size="30" />
                    <h3>Nothing to show here</h3>
                    <p>
                        Your role does not include any of the dashboard's figures. Use the menu to reach
                        the screens you do have.
                    </p>
                </div>
            </div>
        @endif
    </div>
@endsection
