@extends('admin.layouts.app')

@section('title', 'Campaigns')

@section('content')
    <x-page-header
        title="Campaigns"
        subtitle="One message to a lot of people"
        :crumbs="['Customers' => null, 'Campaigns' => null]"
    >
        <x-slot:actions>
            @allows('crm.campaigns.create')
                <a class="btn btn-primary btn-sm" href="{{ route('admin.campaigns.create') }}"
                   data-modal="{{ route('admin.campaigns.create') }}"
                   data-modal-title="New Campaign"
                   data-modal-sub="Write it, choose who gets it"
                   data-modal-size="lg">
                    <x-icon name="plus" :size="15" /> New Campaign
                </a>
            @endallows
        </x-slot:actions>
    </x-page-header>

    {{--
        Said here rather than discovered on send. A campaign written,
        scheduled and then refused because nobody set up a provider is twenty
        minutes wasted for a reason that was knowable at the start.
    --}}
    @unless ($channels['sms'] || $channels['whatsapp'])
        <div class="alert alert-warning" style="margin-bottom:14px">
            <strong>No SMS or WhatsApp provider is set up.</strong>
            You can write and save campaigns, but nothing can be sent until one is configured.
            Until then both channels write to the log instead — useful for trying it out.
        </div>
    @endunless

    <div class="card" data-ajax-list="{{ route('admin.campaigns.index') }}">
        <form method="GET" class="list-toolbar">
            <div class="list-filters">
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" name="status" data-ajax-filter>
                    <option value="">Any status</option>
                    @foreach ($statuses as $value => $meta)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $meta['label'] }}</option>
                    @endforeach
                </select>

                <noscript><button type="submit" class="btn btn-sm">Filter</button></noscript>

                <a class="btn btn-sm btn-ghost" href="{{ route('admin.campaigns.index') }}" data-ajax-reset>Reset</a>
            </div>
        </form>

        <div data-ajax-list-content>
            @include('admin.campaigns._list')
        </div>
    </div>
@endsection
