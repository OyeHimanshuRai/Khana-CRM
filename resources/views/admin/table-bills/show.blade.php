@extends('admin.layouts.app')

@section('title', 'Bill · '.($session->table?->code ?? 'Table'))

@section('content')
    <x-page-header
        :title="'Table '.($session->table?->code ?? '—')"
        :subtitle="$session->partyName().' · seated '.$session->seatedMinutes().' minutes'"
        :crumbs="['Counter' => null, 'Table Bills' => route('admin.table-bills.index'), ($session->table?->code ?? 'Bill') => null]"
    >
        <x-slot:actions>
            {{--
                First, and the only primary button here: on a busy evening this
                is pressed many times a table and settling is pressed once.
            --}}
            @if ($canOrder && ! $session->isClosed())
                <a class="btn btn-primary btn-sm"
                   href="{{ route('admin.table-bills.order-form', $session) }}"
                   data-modal="{{ route('admin.table-bills.order-form', $session) }}"
                   data-modal-title="Order for table {{ $session->table?->code }}"
                   data-modal-sub="Add to the pad, then send it in one ticket">
                    <x-icon name="plus" :size="15" /> Take order
                </a>
            @endif

            @if ($canMove && $targets->isNotEmpty() && ! $session->isClosed())
                <a class="btn btn-sm"
                   href="{{ route('admin.table-bills.merge-form', $session) }}"
                   data-modal="{{ route('admin.table-bills.merge-form', $session) }}"
                   data-modal-title="Move or merge"
                   data-modal-sub="Tables pushed together, or one ticket sent to the next table">
                    <x-icon name="truck" :size="15" /> Move / merge
                </a>
            @endif

            @if ($canWriteOff && ! $session->isClosed())
                <a class="btn btn-sm"
                   href="{{ route('admin.table-bills.write-off-form', $session) }}"
                   data-modal="{{ route('admin.table-bills.write-off-form', $session) }}"
                   data-modal-title="Clear this table unpaid"
                   data-modal-sub="A walkout, or a party that owed nothing">
                    <x-icon name="user-x" :size="15" /> Clear unpaid
                </a>
            @endif

            <a class="btn btn-sm" href="{{ route('admin.table-bills.index') }}">
                <x-icon name="list" :size="15" /> All tables
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card" data-ajax-list="{{ route('admin.table-bills.show', $session) }}">
        <div data-ajax-list-content>
            @include('admin.table-bills._bill')
        </div>
    </div>
@endsection
