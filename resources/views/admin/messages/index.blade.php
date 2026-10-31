@extends('admin.layouts.app')

@section('title', 'Messages')

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/messages.css') }}?v={{ filemtime(public_path('assets/css/messages.css')) }}">
@endpush

@section('content')
    <x-page-header title="Messages" subtitle="Private messages with your team"
                   :crumbs="['Messages' => null]" />

    <div class="msg" id="msg"
         data-me="{{ $me->id }}"
         data-url-conversations="{{ route('admin.messages.conversations') }}"
         data-url-contacts="{{ route('admin.messages.contacts') }}"
         data-url-start="{{ route('admin.messages.start') }}"
         data-url-thread="{{ url('admin/messages/conversations') }}"
         data-can-send="{{ $me->can('messages.chat.create') ? '1' : '0' }}">
        <aside class="msg-side" aria-label="Conversations">
            <header>People</header>
            <div class="msg-search">
                <label class="sr-only" for="msg-q">Find a person</label>
                <input type="search" id="msg-q" class="input" placeholder="Find a person…" autocomplete="off">
            </div>
            <ul class="msg-list" id="msg-list"></ul>
        </aside>

        <section class="msg-main" aria-label="Conversation">
            <header>
                <button type="button" id="msg-back" class="btn btn-icon btn-ghost" aria-label="Back to people" hidden>&larr;</button>
                <span id="msg-title">Pick a conversation</span>
                <x-info label="About messages" align="end">Enter sends; Shift+Enter starts a new line. Messages are encrypted in storage, but not end-to-end encrypted.</x-info>
            </header>
            <div class="msg-thread" id="msg-thread" role="log" aria-live="polite">
                <p class="msg-empty">Choose someone on the left to start.</p>
            </div>
            <form class="msg-form" id="msg-form" hidden>
                <label class="sr-only" for="msg-body">Message</label>
                <textarea id="msg-body" class="input" rows="1" maxlength="2000" placeholder="Message" required></textarea>
                <button type="submit" class="msg-send" id="msg-send" aria-label="Send message">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>
                </button>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/messages.js') }}" defer></script>
@endpush
