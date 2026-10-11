@extends('layouts.app')

@section('title', 'Home')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Today" :subtitle="$outletName" />

    {{-- The four things a storekeeper does every day, one tap away. --}}
    <div class="stat-grid" style="margin-bottom:18px">
        @foreach ([
            ['receive', 'receipts.create', 'truck', 'Receive goods', 'A delivery has arrived'],
            ['issue', 'issues.create', 'zap', 'Issue to kitchen', 'Move stock to the kitchen'],
            ['count', 'counts.create', 'list', 'Count stock', 'Check the shelf against the books'],
            ['wastage', 'wastage.create', 'trash', 'Log wastage', 'Something was spoiled or spilt'],
        ] as [$ability, $route, $icon, $label, $hint])
            @can($ability)
                <a class="stat" href="{{ Route::has($route) ? route($route) : '#' }}" style="text-decoration:none">
                    <div class="stat-icon"><x-khana::icon :name="$icon" :size="20" /></div>
                    <div class="stat-body">
                        <div class="stat-value" style="font-size:16px">{{ $label }}</div>
                        <div class="stat-label">{{ $hint }}</div>
                    </div>
                </a>
            @endcan
        @endforeach
    </div>

    <div class="card" style="margin-bottom:18px">
        <div class="card-header"><h2 class="card-title">Needs attention</h2></div>

        @if ($todo === [])
            <x-khana::empty icon="check" title="Nothing needs attention" text="Stock, recipes and bills are all in order." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($todo as $row)
                            <tr>
                                <td style="width:70px"><span class="badge badge-{{ $row['tone'] }}">{{ $row['count'] }}</span></td>
                                <td>
                                    <strong>{{ $row['label'] }}</strong>
                                    <div class="text-sm text-muted">{{ $row['hint'] }}</div>
                                </td>
                                <td class="col-action">
                                    @if ($row['url'])
                                        <a href="{{ $row['url'] }}" class="btn btn-sm btn-ghost">Open</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Stock on hand</div>
                <div class="stat-value">&#8377;{{ Units::rupees($figures['stock_value']) }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Received today</div>
                <div class="stat-value">&#8377;{{ Units::rupees($figures['received_today']) }}</div>
            </div>
        </div>
        <div class="stat">
            <div class="stat-body">
                <div class="stat-label">Wasted, last 7 days</div>
                <div class="stat-value">&#8377;{{ Units::rupees($figures['wasted_week']) }}</div>
            </div>
        </div>
    </div>
@endsection
