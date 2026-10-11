{{--
    The programme's rules, in the modal.

    The hints talk in money rather than in points, because "5 points per 100"
    and "1 rupee a point" are two numbers whose product nobody computes in
    their head — and the product is what the restaurant gives away on every
    single bill.
--}}

<form method="POST" action="{{ route('admin.loyalty.save') }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @method('PUT')

    <div class="form-section">
        <div class="form-section-title">The programme</div>

        <div class="settings-grid">
            <div class="field">
                <label for="lp-name">Name</label>
                <input id="lp-name" type="text" name="name" class="form-control" required
                       value="{{ $program->name ?: 'Loyalty' }}" maxlength="90" aria-invalid="false">
                <div class="form-hint">What guests see it called.</div>
            </div>

            <div class="field">
                <label class="check" style="margin-top:22px">
                    <input type="checkbox" name="is_active" value="1" @checked($program->is_active)>
                    <span>Running</span>
                </label>
                <div class="form-hint">
                    Switching it off stops earning and spending. Points already held are kept.
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Earning</div>

        <div class="settings-grid">
            <div class="field">
                <label for="lp-earn">Points per 100 spent</label>
                <input id="lp-earn" type="number" name="points_per_hundred" class="form-control" required
                       value="{{ $program->points_per_hundred ?? 5 }}" min="0" step="0.01" aria-invalid="false">
                <div class="form-hint">
                    Always rounded down, so the programme never pays out more than it advertised.
                </div>
            </div>

            <div class="field">
                <label for="lp-min-spend">Minimum bill</label>
                <input id="lp-min-spend" type="number" name="min_spend" class="form-control" required
                       value="{{ $program->min_spend ?? 0 }}" min="0" step="0.01" aria-invalid="false">
                <div class="form-hint">Bills below this earn nothing. 0 means every bill earns.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Spending</div>

        <div class="settings-grid">
            <div class="field">
                <label for="lp-value">One point is worth</label>
                <input id="lp-value" type="number" name="redeem_value" class="form-control" required
                       value="{{ $program->redeem_value ?? 1 }}" min="0" step="0.01" aria-invalid="false">
            </div>

            <div class="field">
                <label for="lp-min">Cannot spend below</label>
                <input id="lp-min" type="number" name="min_redeem_points" class="form-control" required
                       value="{{ $program->min_redeem_points ?? 100 }}" min="0" aria-invalid="false">
                <div class="form-hint">Points. Gives a balance something to build towards.</div>
            </div>

            <div class="field">
                <label for="lp-cap">Most of a bill points may pay</label>
                <input id="lp-cap" type="number" name="max_redeem_percent" class="form-control" required
                       value="{{ $program->max_redeem_percent ?? 50 }}" min="1" max="100" aria-invalid="false">
                <div class="form-hint">
                    Per cent. This is what keeps a loyalty programme from becoming a discount
                    scheme — a guest arriving with 4,000 points still pays something.
                </div>
            </div>

            <div class="field">
                <label for="lp-expiry">Points lapse after</label>
                <input id="lp-expiry" type="number" name="expiry_months" class="form-control"
                       value="{{ $program->expiry_months }}" min="1" max="120" aria-invalid="false"
                       placeholder="Never">
                <div class="form-hint">
                    Months. Empty means never, which is a real choice: a small restaurant that
                    expires nothing has one fewer argument at the till.
                </div>
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save programme</button>
    </div>
</form>
