{{--
    A company's license, shown inside the Companies screen.

    Rendered into the modal body; no <script> may live here. The key reveal
    and copy button are in public/assets/js/license.js: a form marked
    data-license-generate swaps the panel for the one-time key box when it
    succeeds (the plain key is only ever in that single response).
--}}
<div data-license-generate-wrap>
    <div data-license-panel>
        @if ($license === null)
            @if ($tenant->license_exempt)
                <div class="alert alert-info" style="margin-bottom:14px">
                    <strong>No license needed.</strong> {{ $tenant->name }} is exempt, so it works without one.
                    Give it a license below if you want to start controlling its access.
                </div>
            @endif

            @if ($canEdit)
                <form method="POST" action="{{ route('admin.licenses.store') }}" data-ajax data-refresh-list data-license-generate data-toast-success="false">
                    @csrf
                    <input type="hidden" name="tenant_id" value="{{ $tenant->id }}">

                    <div class="settings-grid">
                        <div class="field field-full">
                            <label for="gen-plan">Plan</label>
                            <select id="gen-plan" name="plan_id" class="form-control" required aria-invalid="false">
                                <option value="">Choose a plan…</option>
                                @foreach ($plans as $plan)
                                    <option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->currency }} {{ number_format((float) $plan->monthly_price, 2) }}/month</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="gen-days">Valid for (days)</label>
                            <input id="gen-days" type="number" name="validity_days" class="form-control" min="1" max="3650" value="365" aria-invalid="false">
                        </div>
                        <div class="field">
                            <label for="gen-date">…or expires on</label>
                            <input id="gen-date" type="date" name="expires_at" class="form-control" min="{{ now()->addDay()->toDateString() }}" aria-invalid="false">
                        </div>
                        <div class="field field-full">
                            <label for="gen-notes">Note <span class="text-muted">(optional)</span></label>
                            <input id="gen-notes" type="text" name="notes" class="form-control" maxlength="500" aria-invalid="false">
                            <div class="form-hint">The key is shown once. Send it to {{ $tenant->name }}; only they can enter it.</div>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="btn" data-modal-close>Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate license</button>
                    </div>
                </form>
            @else
                <p class="text-sm text-muted">This company has no license.</p>
            @endif
        @else
            @include('admin.licenses._show')
        @endif

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Close</button>
        </div>
    </div>

    <div data-license-result hidden data-send-url="{{ $sendUrl }}">
        <div class="alert alert-success" style="margin-bottom:12px">
            <strong>Key ready.</strong> Copy it now - it is stored only as a hash and cannot be shown again.
        </div>

        <div class="license-key-box">
            <code data-license-key aria-live="polite"></code>
            <button type="button" class="btn btn-sm" data-license-copy>Copy</button>
        </div>

        <div class="lic-send">
            <label for="lic-send-email" class="lic-label">Send this key to the customer by email</label>
            <div class="lic-line">
                <input id="lic-send-email" type="email" class="input" data-license-email value="{{ $defaultEmail }}" placeholder="customer@email.com" autocomplete="off">
                <button type="button" class="btn btn-sm btn-primary" data-license-send>Send email</button>
            </div>
            <p class="lic-hint">The key works once only, for {{ $tenant->name }} - after it is entered it cannot be used again.</p>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn btn-primary" data-modal-close>Done</button>
        </div>
    </div>
</div>
