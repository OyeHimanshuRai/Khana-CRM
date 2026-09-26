@extends('admin.layouts.app')

@section('title', 'Settlement')

@section('content')
    <x-page-header
        title="Settlement"
        :subtitle="$from->format('j M').' – '.$to->format('j M Y')"
        :crumbs="['Finance' => null, 'Online Payments' => route('admin.online-payments.index'), 'Settlement' => null]"
    >
        <x-slot:actions>
            <a class="btn btn-sm" href="{{ route('admin.online-payments.index') }}">
                <x-icon name="wallet" :size="15" /> Payments
            </a>
        </x-slot:actions>
    </x-page-header>

    {{--
        Deliberately one side of the reconciliation.

        The whole point is to have two independent accounts to compare. A
        report that fetched the provider's own figures and printed them would
        agree with the provider always — including on the day they were wrong.
    --}}
    <div class="alert alert-info" style="margin-bottom:14px">
        <strong>This is what we recorded.</strong>
        Read it next to the provider's own settlement statement — the point of a reconciliation is
        two accounts that were kept separately. A day that disagrees is worth an email; a day that
        matches is worth nothing more than a tick.
    </div>

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="s-from" class="sr-only">From</label>
                <input id="s-from" type="date" name="from" value="{{ $from->toDateString() }}">

                <label for="s-to" class="sr-only">To</label>
                <input id="s-to" type="date" name="to" value="{{ $to->toDateString() }}">

                <button type="submit" class="btn btn-sm">Show</button>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.online-payments.settlements') }}">Last 30 days</a>
            </div>
        </form>

        <div class="table-wrap">
            <table class="table table-list">
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Payments</th>
                        <th>Taken</th>
                        <th>Refunded</th>
                        <th>Net</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($days as $row)
                        <tr>
                            <td class="text-sm">
                                <strong>{{ \Illuminate\Support\Carbon::parse($row['day'])->format('j M Y') }}</strong>
                                <span class="text-xs text-muted" style="display:block">
                                    {{ \Illuminate\Support\Carbon::parse($row['day'])->format('l') }}
                                </span>
                            </td>
                            <td class="text-sm">{{ number_format($row['orders']) }}</td>
                            <td class="text-sm">{{ number_format($row['gross'], 2) }}</td>
                            <td class="text-sm {{ $row['refunded'] > 0 ? 'text-danger' : '' }}">
                                {{ $row['refunded'] > 0 ? number_format($row['refunded'], 2) : '—' }}
                            </td>
                            <td class="text-sm"><strong>{{ number_format($row['net'], 2) }}</strong></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <x-icon name="chart" :size="28" />
                                    <h3>Nothing in this range</h3>
                                    <p class="text-sm">No online payments were taken between those dates.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($days->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th>{{ number_format($totals['orders']) }}</th>
                            <th>{{ number_format($totals['gross'], 2) }}</th>
                            <th>{{ number_format($totals['refunded'], 2) }}</th>
                            <th>{{ number_format($totals['net'], 2) }}</th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
@endsection
