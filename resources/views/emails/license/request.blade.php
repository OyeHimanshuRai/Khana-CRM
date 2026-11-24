@extends('emails.subscriptions.layout')

@section('heading')
    @if ($kind === 'entered') License waiting for approval
    @elseif ($kind === 'requested') License key requested
    @else New customer needs a license key
    @endif
@endsection

@section('intro')
    @if ($kind === 'entered')
        <strong>{{ $tenant->name }}</strong> entered their license key. They cannot use anything until you approve it.
    @elseif ($kind === 'requested')
        <strong>{{ $tenant->name }}</strong> has not received their license key and asked for it. Please contact them.
    @else
        <strong>{{ $tenant->name }}</strong> has just registered. Nothing works for them until you issue a license key and they enter it.
    @endif
@endsection

@section('details')
    <strong style="color:#111827;">Company</strong> {{ $tenant->name }} ({{ $tenant->code }})<br>
    @if ($ownerName)<strong style="color:#111827;">Owner</strong> {{ $ownerName }}<br>@endif
    @if ($ownerEmail)<strong style="color:#111827;">Email</strong> {{ $ownerEmail }}<br>@endif
    @if ($ownerPhone)<strong style="color:#111827;">Phone</strong> {{ $ownerPhone }}<br>@endif
    @if ($license?->plan)<strong style="color:#111827;">Plan</strong> {{ $license->plan->name }}<br>@endif
    @if ($license)<strong style="color:#111827;">License</strong> {{ $license->key_hint }}<br>@endif
@endsection

@section('cta')
    <a href="{{ $adminUrl }}"
       style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">
        {{ $kind === 'entered' ? 'Review and approve' : 'Open Companies' }}
    </a>
@endsection

@section('note')
    @if ($kind === 'entered')
        Open the company's License panel from the Companies list and press Approve.
    @else
        Open the company's License panel from the Companies list and press Generate key. The key is shown once; send it to the customer.
    @endif
@endsection
