@extends('layouts.app')

@section('title', 'Supplier bills')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Supplier bills" subtitle="What you owe, oldest due first" />

    <div class="stat-grid" style="margin-bottom:18px">
        <div class="stat"><div class="stat-body"><div class="stat-label">Owed in all</div><div class="stat-value">&#8377;{{ Units::rupees($owed) }}</div></div></div>
        <div class="stat"><div class="stat-body"><div class="stat-label">Of that, overdue</div><div class="stat-value" style="{{ $overdue > 0 ? 'color:var(--danger)' : '' }}">&#8377;{{ Units::rupees($overdue) }}</div></div></div>
    </div>

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="show" class="sr-only">Show</label>
                <select id="show" name="show" onchange="this.form.submit()">
                    <option value="open" @selected($show === 'open')>Still owed</option>
                    <option value="paid" @selected($show === 'paid')>Paid</option>
                    <option value="all" @selected($show === 'all')>All bills</option>
                </select>
            </div>
        </form>

        @if ($bills->isEmpty())
            <x-khana::empty icon="check" title="{{ $show === 'open' ? 'Nothing is owed' : 'No bills' }}"
                text="A bill is raised when goods are received from a supplier." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Supplier</th><th>Bill</th><th>Due</th><th style="text-align:right">Total</th><th style="text-align:right">Still owed</th><th style="min-width:330px"></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($bills as $bill)
                            <tr>
                                <td><a href="{{ route('suppliers.show', $bill->supplier_id) }}">{{ $bill->supplier?->name }}</a></td>
                                <td>
                                    @if ($bill->receipt)
                                        <a href="{{ route('receipts.show', $bill->receipt->id) }}">{{ $bill->bill_no }}</a>
                                    @else
                                        {{ $bill->bill_no }}
                                    @endif
                                    <div class="text-xs text-muted">{{ $bill->billed_on->format('d M Y') }}</div>
                                </td>
                                <td>
                                    {{ $bill->due_on?->format('d M Y') ?? '—' }}
                                    @if ($bill->isOverdue($today)) <span class="badge badge-danger">Overdue</span> @endif
                                </td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($bill->total_paise) }}</td>
                                <td style="text-align:right"><strong>&#8377;{{ Units::rupees($bill->duePaise()) }}</strong></td>
                                <td>
                                    @if ($bill->status === 'open')
                                        @can('bills.manage')
                                            <form method="POST" action="{{ route('payments.store') }}" style="display:flex;gap:6px;justify-content:flex-end">
                                                @csrf
                                                <input type="hidden" name="supplier_id" value="{{ $bill->supplier_id }}">
                                                <input type="hidden" name="supplier_bill_id" value="{{ $bill->id }}">
                                                <input type="hidden" name="paid_on" value="{{ $today }}">
                                                <input class="form-control" name="amount" value="{{ Units::plain($bill->duePaise() * 100) }}"
                                                       inputmode="decimal" style="width:110px" aria-label="Amount to pay">
                                                <select class="form-control" name="method" style="width:130px" aria-label="Paid by">
                                                    @foreach ($methods as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-primary">Pay</button>
                                            </form>
                                        @endcan
                                    @else
                                        <span class="badge badge-success">Paid</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $bills])
        @endif
    </div>
@endsection
