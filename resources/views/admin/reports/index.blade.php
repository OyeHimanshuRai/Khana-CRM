@extends('admin.layouts.app')

@section('title', 'Reports')

@section('content')
    <x-page-header
        title="Reports"
        subtitle="Every figure is for the shop you are working in, and cancelled invoices are never counted as sales."
        :crumbs="['Insights' => null, 'Reports' => null]"
    />

    @if ($reports->isEmpty())
        <div class="card">
            <div class="empty" style="padding:44px 20px">
                <x-icon name="chart" :size="30" />
                <h3>No reports available</h3>
                <p class="text-sm">Your role does not include any of them.</p>
            </div>
        </div>
    @else
        <div class="dash-columns">
            @foreach ($reports as $key => $meta)
                <a class="card report-card" href="{{ route('admin.reports.show', $key) }}">
                    <div class="card-body" style="display:flex;gap:12px;align-items:flex-start">
                        <div class="stat-icon"><x-icon :name="$meta['icon']" :size="20" /></div>
                        <div style="min-width:0">
                            <div class="card-title" style="margin-bottom:2px">{{ $meta['label'] }}</div>
                            <div class="text-sm text-muted">{{ $meta['blurb'] }}</div>
                            @unless ($meta['dated'])
                                {{-- Said plainly: these are a snapshot, not a
                                     period, and offering a date range would
                                     promise history the system does not keep. --}}
                                <div class="text-xs text-muted" style="margin-top:5px">
                                    As at today
                                </div>
                            @endunless
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
