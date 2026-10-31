@extends('admin.layouts.app')

@section('title', $ticket->reference)

@section('content')
    {{--
        One conversation (§2).

        The thread below is rendered from `$replies`, which the controller
        already narrowed: the desk gets every message, a restaurant gets
        `visibleReplies()` with the internal notes filtered out. This template
        does not decide that, and must not — one boundary, in one place.

        It still marks internal notes visibly, because the desk reading its own
        screen needs to know which of these the customer can see.
    --}}
    <x-page-header
        :title="$ticket->subject"
        :subtitle="$ticket->reference.' · '.$ticket->categoryLabel().' · raised '.$ticket->created_at?->format('j M Y, g:i a')"
        :crumbs="['Support' => route('admin.support.index'), $ticket->reference => null]"
    >
        <x-slot:actions>
            @if ($ticket->isClosed())
                @allows('support.tickets.edit')
                    <form method="POST" action="{{ route('admin.support.reopen', $ticket) }}" data-ajax>
                        @csrf @method('PUT')
                        <button type="submit" class="btn">Reopen</button>
                    </form>
                @endallows
            @else
                @allows('support.tickets.edit')
                    @if ($ticket->status !== App\Models\SupportTicket::RESOLVED)
                        <form method="POST" action="{{ route('admin.support.resolve', $ticket) }}" data-ajax>
                            @csrf @method('PUT')
                            <button type="submit" class="btn">Mark resolved</button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('admin.support.close', $ticket) }}" data-ajax
                          onsubmit="return confirm('Close {{ $ticket->reference }}? A reply will no longer reopen it.')">
                        @csrf @method('PUT')
                        <button type="submit" class="btn btn-ghost">Close</button>
                    </form>
                @endallows
            @endif

            <a class="btn btn-ghost" href="{{ route('admin.support.index') }}">Back to list</a>
        </x-slot:actions>
    </x-page-header>

    <div class="settings-grid">
        {{-- ------------------------------------------------- the thread -- --}}
        <div class="field field-full">
            <div class="card">
                <div class="card-head">
                    <h2 class="card-title">Conversation</h2>
                    <div>
                        <span class="badge badge-{{ $ticket->statusTone() }}">{{ $ticket->statusLabel() }}</span>
                        <span class="badge badge-{{ $ticket->priorityTone() }}">{{ $ticket->priorityLabel() }}</span>
                    </div>
                </div>

                <div class="card-body">
                    {{--
                        The opening message lives on the ticket rather than as
                        the first reply — see the migration — so it is drawn
                        here rather than inside the loop.
                    --}}
                    <article class="support-msg is-customer">
                        <header class="support-msg-head">
                            <strong>{{ $ticket->opener?->name ?? 'Deleted user' }}</strong>
                            <span class="text-xs text-muted">
                                {{ $ticket->created_at?->format('j M Y, g:i a') }}
                            </span>
                        </header>
                        <div class="support-msg-body">{!! nl2br(e($ticket->body)) !!}</div>
                    </article>

                    @foreach ($replies as $reply)
                        <article class="support-msg is-{{ $reply->side() }} @if ($reply->internal) is-internal @endif">
                            <header class="support-msg-head">
                                <strong>{{ $reply->byLabel() }}</strong>

                                @if ($reply->from_staff)
                                    <span class="badge badge-info">Support</span>
                                @endif

                                @if ($reply->internal)
                                    {{-- Only the desk ever renders one of these. --}}
                                    <span class="badge badge-warning">Internal note — not visible to the customer</span>
                                @endif

                                <span class="text-xs text-muted">
                                    {{ $reply->created_at?->format('j M Y, g:i a') }}
                                </span>
                            </header>
                            <div class="support-msg-body">{!! nl2br(e($reply->body)) !!}</div>
                        </article>
                    @endforeach

                    {{-- ------------------------------------------ reply -- --}}
                    @if ($ticket->acceptsReplies())
                        <form method="POST" action="{{ route('admin.support.reply', $ticket) }}"
                              data-ajax
                              style="margin-top:18px">
                            @csrf

                            <div class="field field-full">
                                <label for="tk-reply" class="sr-only">Your reply</label>
                                <textarea id="tk-reply" name="body" class="form-control" rows="5" required
                                          maxlength="10000" aria-invalid="false"
                                          placeholder="{{ $canManage ? 'Reply to the restaurant…' : 'Add to this ticket…' }}"></textarea>
                            </div>

                            <div class="modal-actions" style="justify-content:space-between">
                                @if ($canManage)
                                    {{--
                                        Desk-only, and the controller checks the
                                        same right again before honouring it —
                                        a hidden checkbox is not a closed door.
                                    --}}
                                    <label class="check">
                                        <input type="checkbox" name="internal" value="1">
                                        <span>Internal note (the customer will not see this)</span>
                                    </label>
                                @else
                                    <span></span>
                                @endif

                                <button type="submit" class="btn btn-primary">Send</button>
                            </div>
                        </form>
                    @else
                        <p class="text-sm text-muted" style="margin-top:18px">
                            This ticket was closed
                            {{ $ticket->closed_at?->diffForHumans() }}@if ($ticket->closer) by {{ $ticket->closer->name }}@endif.
                            Reopen it to carry on the conversation.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ------------------------------------------------- the details -- --}}
        <div class="field field-full">
            <div class="card">
                <div class="card-head"><h2 class="card-title">Details</h2></div>

                <div class="card-body">
                    {{-- Each fact wrapped in its own div: .sec-facts is a grid
                         and lays out children, not dt/dd pairs. --}}
                    <dl class="sec-facts">
                        @if ($canManage)
                            <div>
                                <dt>Company</dt>
                                <dd>{{ $ticket->tenant?->name ?? '—' }}</dd>
                            </div>
                        @endif

                        <div>
                            <dt>Branch</dt>
                            <dd>{{ $ticket->shop?->name ?? 'Not about one branch' }}</dd>
                        </div>

                        <div>
                            <dt>Raised by</dt>
                            <dd>{{ $ticket->opener?->name ?? 'Deleted user' }}</dd>
                        </div>

                        <div>
                            <dt>First reply</dt>
                            <dd>
                                @if ($ticket->firstResponseMinutes() === null)
                                    <span class="text-muted">Not answered yet</span>
                                @elseif ($ticket->firstResponseMinutes() < 60)
                                    {{ $ticket->firstResponseMinutes() }} minutes
                                @else
                                    {{ round($ticket->firstResponseMinutes() / 60, 1) }} hours
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt>Last activity</dt>
                            <dd>{{ $ticket->lastMovedAt()->diffForHumans() }}</dd>
                        </div>

                        @if ($ticket->resolved_at)
                            <div>
                                <dt>Resolved</dt>
                                <dd>{{ $ticket->resolved_at->format('j M Y, g:i a') }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($canManage)
                        <hr>

                        <form method="POST" action="{{ route('admin.support.assign', $ticket) }}" data-ajax>
                            @csrf @method('PUT')
                            <div class="field">
                                <label for="tk-assign">Assigned to</label>
                                <select id="tk-assign" name="assigned_to" class="form-control" aria-invalid="false">
                                    <option value="">Nobody — back to the pool</option>
                                    @foreach ($agents as $agent)
                                        <option value="{{ $agent->id }}" @selected($ticket->assigned_to === $agent->id)>
                                            {{ $agent->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sm">Assign</button>
                        </form>

                        <form method="POST" action="{{ route('admin.support.priority', $ticket) }}" data-ajax
                              style="margin-top:14px">
                            @csrf @method('PUT')
                            <div class="field">
                                <label for="tk-priority-set">Priority</label>
                                <select id="tk-priority-set" name="priority" class="form-control" aria-invalid="false">
                                    @foreach ($priorities as $key => $label)
                                        <option value="{{ $key }}" @selected($ticket->priority === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sm">Update</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
