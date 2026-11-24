{{--
    Generate a license key.

    Rendered into the modal body; no <script> may live here - the key reveal
    and copy button are in public/assets/js/license.js and listen for this
    form's ajax:success. The plain key exists only in that one response.
--}}
<div data-license-generate-wrap>
    <form method="POST" action="{{ route('admin.licenses.store') }}" data-ajax data-refresh-list data-license-generate data-toast-success="false">
        @csrf

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

            <div class="field field-full">
                <label for="gen-tenant">Assign to customer <span class="text-muted">(optional)</span></label>
                <select id="gen-tenant" name="tenant_id" class="form-control" aria-invalid="false">
                    <option value="">Anyone — the first customer to enter it</option>
                    @foreach ($tenants as $tenant)
                        <option value="{{ $tenant->id }}">{{ $tenant->name }} ({{ $tenant->code }})</option>
                    @endforeach
                </select>
                <div class="form-hint">An assigned key can only be entered by that customer.</div>
            </div>

            <div class="field">
                <label for="gen-days">Valid for (days)</label>
                <input id="gen-days" type="number" name="validity_days" class="form-control" min="1" max="3650" value="365" aria-invalid="false">
            </div>

            <div class="field">
                <label for="gen-date">…or expires on</label>
                <input id="gen-date" type="date" name="expires_at" class="form-control" min="{{ now()->addDay()->toDateString() }}" aria-invalid="false">
                <div class="form-hint">A date overrides the days.</div>
            </div>

            <div class="field field-full">
                <label for="gen-notes">Note <span class="text-muted">(optional)</span></label>
                <input id="gen-notes" type="text" name="notes" class="form-control" maxlength="500" aria-invalid="false">
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Generate key</button>
        </div>
    </form>

    <div data-license-result hidden>
        <div class="alert alert-success" style="margin-bottom:12px">
            <strong>License generated.</strong> Copy the key now and send it to the customer - it is stored only as a hash and cannot be shown again.
        </div>
        <div class="license-key-box">
            <code data-license-key aria-live="polite"></code>
            <button type="button" class="btn btn-sm" data-license-copy>Copy</button>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn btn-primary" data-modal-close>Done</button>
        </div>
    </div>
</div>
