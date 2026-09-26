{{--
    Write a campaign and choose who gets it.

    No <script> here — markup injected via innerHTML never runs its scripts.
    The live audience count comes from campaigns.js, which is registered
    globally for exactly that reason.

    The audience count is the most important control on this form. A campaign
    sent to the wrong list cannot be recalled, and "214 people" is the only
    thing that catches it in time — which is why it sits beside the rules
    rather than on a confirmation step nobody reads.
--}}

@php
    $isNew = ! $campaign->exists;
    $segment = $campaign->segment ?? [];
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.campaigns.store') : route('admin.campaigns.update', $campaign) }}"
      data-ajax data-close-modal data-refresh-list
      data-campaign-form
      data-preview-url="{{ route('admin.campaigns.preview') }}">
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">The message</div>

        <div class="settings-grid">
            <div class="field">
                <label for="cp-name">Name</label>
                <input id="cp-name" type="text" name="name" class="form-control" required
                       value="{{ $campaign->name }}" maxlength="120" aria-invalid="false"
                       placeholder="Lapsed regulars, September">
                <div class="form-hint">For your list, not for the guest.</div>
            </div>

            <div class="field">
                <label for="cp-channel">Send by</label>
                <select id="cp-channel" name="channel" class="form-control" required aria-invalid="false">
                    <option value="sms" @selected($campaign->channel === 'sms')>
                        SMS @unless ($channels['sms']) (no provider set up) @endunless
                    </option>
                    <option value="whatsapp" @selected($campaign->channel === 'whatsapp')>
                        WhatsApp @unless ($channels['whatsapp']) (no provider set up) @endunless
                    </option>
                </select>
            </div>

            <div class="field field-full">
                <label for="cp-body">Message</label>
                <textarea id="cp-body" name="body" class="form-control" rows="4" required
                          maxlength="2000" aria-invalid="false"
                          placeholder="Hello {name}, we have missed you. Come in this week and your first drink is on us.">{{ $campaign->body }}</textarea>
                <div class="form-hint">
                    <code>{name}</code> becomes the customer's name, or "there" when there is none.
                    An SMS is charged per 160 characters, so a long message is several messages.
                </div>
            </div>

            <div class="field field-full">
                <label for="cp-template">WhatsApp template</label>
                <input id="cp-template" type="text" name="template" class="form-control"
                       value="{{ $campaign->template }}" maxlength="120" aria-invalid="false"
                       placeholder="Leave empty unless sending by WhatsApp">
                <div class="form-hint">
                    WhatsApp will not let a business start a conversation with free text — it must be
                    a template Meta has approved. That is Meta's rule, not this system's. The message
                    above is used as the body for providers that accept one.
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Who gets it</div>

        <div class="settings-grid">
            <div class="field">
                <label for="cp-within">Came in the last</label>
                <input id="cp-within" type="number" name="visited_within_days" class="form-control"
                       value="{{ $segment['visited_within_days'] ?? '' }}" min="1" max="3650"
                       aria-invalid="false" placeholder="Any" data-segment>
                <div class="form-hint">Days. Leave empty for no limit.</div>
            </div>

            <div class="field">
                <label for="cp-lapsed">Has not come for</label>
                <input id="cp-lapsed" type="number" name="not_visited_for_days" class="form-control"
                       value="{{ $segment['not_visited_for_days'] ?? '' }}" min="1" max="3650"
                       aria-invalid="false" placeholder="Any" data-segment>
                <div class="form-hint">
                    Days. Only counts people who HAVE been in at some point — otherwise this is
                    every customer record ever created.
                </div>
            </div>

            <div class="field">
                <label for="cp-spend">Has spent at least</label>
                <input id="cp-spend" type="number" name="min_spend" class="form-control"
                       value="{{ $segment['min_spend'] ?? '' }}" min="0" step="0.01"
                       aria-invalid="false" placeholder="Any" data-segment>
            </div>

            <div class="field">
                <label for="cp-visits">Has visited at least</label>
                <input id="cp-visits" type="number" name="min_visits" class="form-control"
                       value="{{ $segment['min_visits'] ?? '' }}" min="1"
                       aria-invalid="false" placeholder="Any" data-segment>
                <div class="form-hint">Times.</div>
            </div>

            <div class="field field-full">
                <label class="check">
                    <input type="checkbox" name="has_points" value="1"
                           @checked(! empty($segment['has_points'])) data-segment>
                    <span>Holds loyalty points</span>
                </label>
            </div>
        </div>

        {{-- Filled in by campaigns.js as the rules change. --}}
        <div class="alert alert-info" style="margin-top:10px" data-audience>
            Anybody with a mobile number.
        </div>

        <p class="text-xs text-muted">
            Customers with no mobile number are never included — a row for somebody unreachable is a
            failure recorded for a thing that was never possible.
        </p>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Save as draft' : 'Save changes' }}
        </button>
    </div>

    <p class="text-xs text-muted" style="margin-top:8px">
        Saving does not send anything. Open the campaign and press Send when you are ready.
    </p>
</form>
