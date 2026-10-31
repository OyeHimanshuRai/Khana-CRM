{{--
    Put a business on a plan, or move it to another one.

    Rendered into the modal body; no <script> may live here.

    Changing plan mid-term keeps the days already paid for - `ends_at`
    carries over rather than restarting. The note below says so, because the
    alternative reading ("did I just wipe three weeks they paid for?") is the
    one somebody will have while clicking.
--}}

@php
    $isChange = $subscription !== null;
@endphp

<form method="POST" action="{{ route('admin.subscriptions.store', $tenant) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf

    @if ($isChange)
        <div class="alert alert-info" style="margin-bottom:14px">
            <strong>{{ $tenant->name }} is on {{ $subscription->plan?->name ?? 'a plan' }}</strong>
            @if ($subscription->ends_at)
                until {{ $subscription->ends_at->format('j M Y') }}.
                Those days carry over — moving plan does not restart the clock or charge twice
                for the same fortnight.
            @else
                with no end date.
            @endif
        </div>
    @endif

    <div class="settings-grid">
        <div class="field field-full">
            <label for="sub-plan">Plan</label>
            <select id="sub-plan" name="plan_id" class="form-control" required aria-invalid="false">
                <option value="">Choose a plan…</option>
                @foreach ($plans as $plan)
                    <option value="{{ $plan->id }}" @selected($subscription?->plan_id === $plan->id)>
                        {{ $plan->name }}
                        — {{ $plan->currency }} {{ number_format((float) $plan->monthly_price, 2) }}/month
                        @unless ($plan->is_active) (withdrawn from sale) @endunless
                    </option>
                @endforeach
            </select>
            <div class="form-hint">
                The plan is read live: adding a module to it later gives it to everybody on it.
                The price below is not — it is frozen at what this business agreed to pay.
            </div>
        </div>

        <div class="field">
            <label for="sub-period">Billing period</label>
            <select id="sub-period" name="billing_period" class="form-control" required aria-invalid="false">
                <option value="monthly" @selected(($subscription->billing_period ?? 'monthly') === 'monthly')>Monthly</option>
                <option value="yearly" @selected(($subscription->billing_period ?? '') === 'yearly')>Yearly</option>
            </select>
        </div>

        <div class="field">
            <label for="sub-price">Agreed price</label>
            <input id="sub-price" type="number" name="price" class="form-control"
                   min="0" step="0.01" aria-invalid="false" placeholder="The plan's price">
            <div class="form-hint">
                Leave empty to charge the list price. Fill it in for a negotiated rate — it
                will not change when the plan is re-priced.
            </div>
        </div>

        <div class="field">
            <label for="sub-trial">Free trial</label>
            <input id="sub-trial" type="number" name="trial_days" class="form-control"
                   min="0" max="365" aria-invalid="false"
                   placeholder="{{ $isChange ? '0' : "The plan's own trial" }}">
            <div class="form-hint">
                Days. {{ $isChange
                    ? 'Usually 0 — a business changing plan has already had its trial.'
                    : "Empty uses the plan's own trial length." }}
            </div>
        </div>

        <div class="field field-full">
            <label class="check">
                <input type="checkbox" name="perpetual" value="1">
                <span>No end date — complimentary or in-house account</span>
            </label>
            <div class="form-hint">
                Leave unticked to sell the plan: with no trial and no time left, the term starts when the first payment is recorded.
            </div>
        </div>

        <div class="field field-full">
            <label for="sub-note">Note</label>
            <input id="sub-note" type="text" name="note" class="form-control"
                   maxlength="255" aria-invalid="false"
                   placeholder="Agreed on the call with Priya, 14 Sept">
            <div class="form-hint">Why this deal looks the way it does. Nobody remembers in March.</div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isChange ? 'Change plan' : 'Start subscription' }}
        </button>
    </div>
</form>
