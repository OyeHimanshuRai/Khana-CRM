@extends('layouts.app')

@section('title', 'Suppliers')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Suppliers" subtitle="Who you buy from, and what you owe each" />

    <div class="card" style="margin-bottom:18px">
        @if ($suppliers->isEmpty())
            <x-khana::empty icon="users" title="No suppliers yet" text="Add the people you buy from. Receiving goods from one raises their bill for you." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Supplier</th><th>Contact</th><th>Pay within</th><th style="text-align:right">You owe</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($suppliers as $supplier)
                            <tr @unless ($supplier->is_active) style="opacity:.55" @endunless>
                                <td>
                                    <a href="{{ route('suppliers.show', $supplier->id) }}"><strong>{{ $supplier->name }}</strong></a>
                                    @unless ($supplier->is_active) <span class="badge">Off</span> @endunless
                                </td>
                                <td class="text-sm">
                                    {{ $supplier->contact_name }}
                                    @if ($supplier->phone) <div class="text-muted">{{ $supplier->phone }}</div> @endif
                                </td>
                                <td>{{ $supplier->terms_days ? $supplier->terms_days.' days' : 'On delivery' }}</td>
                                <td style="text-align:right">
                                    @if ($owed[$supplier->id] > 0)
                                        <strong>&#8377;{{ Units::rupees($owed[$supplier->id]) }}</strong>
                                    @elseif ($owed[$supplier->id] < 0)
                                        <span class="badge badge-info">&#8377;{{ Units::rupees(-$owed[$supplier->id]) }} in advance</span>
                                    @else
                                        <span class="text-muted">Nothing</span>
                                    @endif
                                </td>
                                <td class="col-action"><a href="{{ route('suppliers.show', $supplier->id) }}" class="btn btn-sm btn-ghost">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @can('purchasing.manage')
        <form method="POST" action="{{ route('suppliers.store') }}" class="card" style="max-width:820px">
            @csrf
            <div class="card-header"><h2 class="card-title">Add a supplier</h2></div>
            <div class="card-body">
                @include('suppliers.fields', ['supplier' => null])
            </div>
            <div class="card-footer" style="display:flex;justify-content:flex-end">
                <button type="submit" class="btn btn-primary">Add supplier</button>
            </div>
        </form>
    @endcan
@endsection
