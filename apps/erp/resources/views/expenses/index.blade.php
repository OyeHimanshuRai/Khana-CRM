@extends('layouts.app')

@section('title', 'Expenses')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Expenses" subtitle="Money spent on something that is not stock" />

    @can('bills.manage')
        <form method="POST" action="{{ route('expenses.store') }}" class="card" style="margin-bottom:18px">
            @csrf
            <div class="card-body">
                <div class="form-row">
                    <div class="field">
                        <label class="form-label" for="category">What on</label>
                        <select class="form-control" id="category" name="category" required>
                            @foreach ($categories as $key => $label) <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option> @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label" for="amount">Amount (&#8377;)</label>
                        <input class="form-control" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required>
                        @error('amount')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label class="form-label" for="spent_on">On</label>
                        <input class="form-control" type="date" id="spent_on" name="spent_on" value="{{ old('spent_on', $today) }}" required>
                    </div>
                    <div class="field">
                        <label class="form-label" for="method">Paid by</label>
                        <select class="form-control" id="method" name="method">
                            @foreach ($methods as $key => $label) <option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option> @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label class="form-label" for="note">Note <span class="text-muted">(optional)</span></label>
                        <input class="form-control" id="note" name="note" value="{{ old('note') }}" maxlength="500">
                    </div>
                </div>
            </div>
            <div class="card-footer" style="display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary">Add expense</button>
            </div>
        </form>
    @endcan

    <div class="card">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="month" class="sr-only">Month</label>
                <input id="month" type="month" name="month" value="{{ $month }}" onchange="this.form.submit()">
                <span class="text-sm"><strong>&#8377;{{ Units::rupees($total) }}</strong> this month</span>
                @foreach ($byCategory as $key => $amount)
                    <span class="pill">{{ $categories[$key] ?? $key }} &#8377;{{ Units::rupees((int) $amount) }}</span>
                @endforeach
            </div>
        </form>

        @if ($expenses->isEmpty())
            <x-khana::empty icon="wallet" title="No expenses this month" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>What on</th><th>Note</th><th>Paid by</th><th style="text-align:right">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($expenses as $expense)
                            <tr>
                                <td>{{ $expense->spent_on->format('d M Y') }}</td>
                                <td>{{ $expense->categoryLabel() }}</td>
                                <td class="text-sm text-muted">{{ $expense->note }}</td>
                                <td>{{ $methods[$expense->method] ?? $expense->method }}</td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($expense->amount_paise) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $expenses])
        @endif
    </div>
@endsection
