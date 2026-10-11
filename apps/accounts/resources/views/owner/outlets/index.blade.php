@extends('khana::layouts.app')

@section('title', 'Outlets')

@section('content')
    <x-khana::page-header title="Outlets" subtitle="The restaurants and cafes you run">
        <x-slot:actions>
            <a href="{{ route('outlets.create') }}" class="btn btn-primary">
                <x-khana::icon name="plus" :size="16" /> Add outlet
            </a>
        </x-slot:actions>
    </x-khana::page-header>

    @if ($outlets->isEmpty())
        <x-khana::empty icon="store" title="No outlets yet" text="Add your first outlet to start trading.">
            <a href="{{ route('outlets.create') }}" class="btn btn-primary">Add outlet</a>
        </x-khana::empty>
    @else
        <div class="card">
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Outlet</th><th>Code</th><th>City</th><th>Plan</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($outlets as $outlet)
                            @php $sub = $outlet->subscriptions->sortByDesc('id')->first(); @endphp
                            <tr>
                                <td><strong>{{ $outlet->name }}</strong></td>
                                <td>{{ $outlet->code }}</td>
                                <td>{{ $outlet->city }}</td>
                                <td>{{ $sub?->plan->name ?? '—' }}</td>
                                <td>
                                    @if ($sub)
                                        <span class="badge badge-{{ $sub->stateTone() }}">{{ $sub->stateLabel() }}</span>
                                    @else
                                        <span class="text-muted">No plan</span>
                                    @endif
                                </td>
                                <td class="col-action">
                                    <a href="{{ route('outlets.edit', $outlet) }}" class="btn btn-sm btn-ghost">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
