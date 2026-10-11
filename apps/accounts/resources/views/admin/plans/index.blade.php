@extends('khana::layouts.app')

@section('title', 'Plans')

@section('content')
    <x-khana::page-header title="Plans" subtitle="What the platform sells">
        <x-slot:actions>
            <a href="{{ route('admin.plans.create') }}" class="btn btn-primary">
                <x-khana::icon name="plus" :size="16" /> New plan
            </a>
        </x-slot:actions>
    </x-khana::page-header>

    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Name</th><th>Code</th><th>Products</th><th>Monthly</th><th>Yearly</th><th>Trial</th><th>Active</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($plans as $plan)
                        <tr>
                            <td><strong>{{ $plan->name }}</strong></td>
                            <td>{{ $plan->code }}</td>
                            <td>{{ implode(' + ', $plan->productLabels()) }}</td>
                            <td>₹{{ number_format($plan->monthly_price_paise / 100) }}</td>
                            <td>{{ $plan->yearly_price_paise ? '₹'.number_format($plan->yearly_price_paise / 100) : '—' }}</td>
                            <td>{{ $plan->trial_days }} days</td>
                            <td>
                                <span class="badge badge-{{ $plan->is_active ? 'success' : 'danger' }}">{{ $plan->is_active ? 'Yes' : 'No' }}</span>
                            </td>
                            <td class="col-action"><a href="{{ route('admin.plans.edit', $plan) }}" class="btn btn-sm btn-ghost">Edit</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
