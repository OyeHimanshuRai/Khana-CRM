{{--
    One rating, and the reply to it.

    What the guest wrote is rendered, never editable. The only field on this
    screen belongs to the manager.
--}}

<div class="sec-name">{{ $feedback->rating }} out of 5</div>
<div class="text-sm text-muted">
    {{ $feedback->fromLabel() }} · {{ $feedback->created_at?->format('l, j F, g:i a') }}
</div>

<div style="display:flex;flex-wrap:wrap;gap:7px;margin:8px 0 14px">
    <span class="badge badge-{{ $feedback->tone() }}">Overall {{ $feedback->rating }}</span>
    @if ($feedback->food_rating)
        <span class="badge badge-muted">Food {{ $feedback->food_rating }}</span>
    @endif
    @if ($feedback->service_rating)
        <span class="badge badge-muted">Service {{ $feedback->service_rating }}</span>
    @endif
    @if ($feedback->session?->table)
        <span class="badge badge-info">Table {{ $feedback->session->table->name }}</span>
    @endif
</div>

@if ($feedback->comment)
    <div class="alert alert-info" style="margin-bottom:14px">
        {{ $feedback->comment }}
    </div>
@else
    <p class="text-sm text-muted">They left a rating and no comment.</p>
@endif

<dl class="sec-facts">
    <div><dt>Mobile</dt><dd>{{ $feedback->guest_mobile ?: '—' }}</dd></div>
    <div><dt>Order</dt><dd>{{ $feedback->order?->order_number ?? '—' }}</dd></div>
    <div><dt>Customer record</dt><dd>{{ $feedback->customer?->name ?? 'Walk-in' }}</dd></div>
</dl>

@if ($feedback->isAnswered())
    <div class="form-section" style="margin-top:14px">
        <div class="form-section-title">Replied</div>
        <p class="text-sm">{{ $feedback->response }}</p>
        <p class="text-xs text-muted">
            {{ $feedback->responder?->name ?? 'Somebody' }},
            {{ $feedback->responded_at->format('j M Y, g:i a') }}
        </p>
    </div>
@endif

@allows('crm.feedback.edit')
    <div class="form-section">
        <div class="form-section-title">{{ $feedback->isAnswered() ? 'Change the reply' : 'Reply' }}</div>

        <form method="POST" action="{{ route('admin.feedback.respond', $feedback) }}"
              data-ajax data-close-modal data-refresh-list>
            @csrf
            @method('PUT')

            <div class="field field-full">
                <label for="fb-response" class="sr-only">Your reply</label>
                <textarea id="fb-response" name="response" class="form-control" rows="3" required
                          maxlength="2000" aria-invalid="false"
                          placeholder="What was done about it">{{ $feedback->response }}</textarea>
                <div class="form-hint">
                    Recorded against this rating so the next person to open it can see it was
                    dealt with. It is not sent to the guest from here.
                </div>
            </div>

            <div style="margin-top:10px">
                <button type="submit" class="btn btn-primary">Save reply</button>
            </div>
        </form>
    </div>
@endallows

<div class="modal-actions">
    <button type="button" class="btn" data-modal-close>Close</button>
</div>
