@extends('layouts.app')

@section('title', 'Transfers')

@php use App\Support\Units; @endphp

@section('page')
    <x-khana::page-header title="Transfers" subtitle="Stock sent to, and come from, your other outlets">
        <x-slot:actions>
            @if ($others->isNotEmpty())
                @can('transfer')
                    <a href="{{ route('transfers.create') }}" class="btn btn-primary">
                        <x-khana::icon name="plus" :size="16" /> Send stock
                    </a>
                @endcan
            @endif
        </x-slot:actions>
    </x-khana::page-header>

    {{-- What another outlet has sent here and nobody has taken in yet. --}}
    @if ($awaited->isNotEmpty())
        <div class="card" style="margin-bottom:18px">
            <div class="card-header"><h2 class="card-title">Waiting to be received</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                        @foreach ($awaited as $transfer)
                            <tr>
                                <td style="width:120px"><strong>{{ $transfer->number }}</strong></td>
                                <td>
                                    From {{ $transfer->fromName() }}, sent {{ $transfer->sent_on->format('d M') }}
                                    <div class="text-sm text-muted">
                                        {{ $transfer->lines->take(4)->map(fn ($line) => $line->item->name.' '.$line->item->show($line->qty))->implode(', ') }}
                                        @if ($transfer->lines->count() > 4) and {{ $transfer->lines->count() - 4 }} more @endif
                                    </div>
                                </td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($transfer->value_paise) }}</td>
                                <td class="col-action">
                                    <a href="{{ route('transfers.show', $transfer->id) }}" class="btn btn-sm btn-primary">
                                        {{ auth()->user()->can('receive') ? 'Receive' : 'Open' }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        @if ($transfers->isEmpty())
            @if ($others->isEmpty())
                <x-khana::empty icon="truck" title="Nowhere to send stock yet"
                    text="A transfer moves stock between two outlets of your business that both use ERP. This is the only one so far - open another in Khana Accounts and it will appear here." />
            @else
                <x-khana::empty icon="truck" title="Nothing sent or received yet"
                    text="Send stock to another outlet and it leaves your shelf now and joins theirs when they receive it.">
                    @can('transfer')
                        <a href="{{ route('transfers.create') }}" class="btn btn-primary">Send stock</a>
                    @endcan
                </x-khana::empty>
            @endif
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Transfer</th><th>Sent</th><th>Where</th><th>What</th><th style="text-align:right">Worth</th><th>Status</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($transfers as $transfer)
                            @php $in = $transfer->isComingTo($outlet); @endphp
                            <tr>
                                <td><a href="{{ route('transfers.show', $transfer->id) }}"><strong>{{ $transfer->number }}</strong></a></td>
                                <td>{{ $transfer->sent_on->format('d M Y') }}</td>
                                <td>
                                    {{ $in ? 'From '.$transfer->fromName() : 'To '.$transfer->toName() }}
                                    @if ($transfer->user) <div class="text-xs text-muted">Sent by {{ $transfer->user->name }}</div> @endif
                                </td>
                                <td class="text-sm">
                                    {{ $transfer->lines->take(3)->map(fn ($line) => $line->item->name.' '.$line->item->show($line->qty))->implode(', ') }}
                                    @if ($transfer->lines->count() > 3) and {{ $transfer->lines->count() - 3 }} more @endif
                                </td>
                                <td style="text-align:right">&#8377;{{ Units::rupees($transfer->value_paise) }}</td>
                                <td>
                                    <span class="badge badge-{{ $transfer->statusTone() }}">{{ $transfer->statusLabel() }}</span>
                                    @if ($transfer->short_paise > 0) <div class="text-xs" style="color:var(--danger)">&#8377;{{ Units::rupees($transfer->short_paise) }} short</div> @endif
                                </td>
                                <td class="col-action"><a href="{{ route('transfers.show', $transfer->id) }}" class="btn btn-sm btn-ghost">Open</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @include('partials.pager', ['rows' => $transfers])
        @endif
    </div>
@endsection
