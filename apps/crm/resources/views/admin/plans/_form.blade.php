{{--
    Add / Edit plan, rendered straight into the modal body.

    No <script> here - markup injected via innerHTML never runs its scripts.
    The submit, the toasts and the inline field errors come from app.js.

    Two things on this form are easy to get wrong, so both say so in words
    rather than relying on the reader knowing:

      "every module"   is stored as null, not as a ticked list, so a module
                       added next year belongs to the plan without anybody
                       editing it. Ticking the box makes the list below
                       irrelevant - which the hint states plainly, because a
                       control that silently stops mattering is worse than
                       one that is disabled.

      an empty limit   means unlimited, not zero. Zero is a real answer
                       somebody might want, so the two cannot share a
                       representation.
--}}

@php
    $isNew = ! $plan->exists;
    $selected = $plan->modules ?? [];
    $everything = $plan->exists ? $plan->modules === null : true;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.plans.store') : route('admin.plans.update', $plan) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Identity</div>

        <div class="settings-grid">
            <div class="field">
                <label for="plan-name">Plan name</label>
                <input id="plan-name" type="text" name="name" class="form-control" required
                       value="{{ $plan->name }}" autocomplete="off" aria-invalid="false"
                       data-slug-source>
                <div class="form-hint">What a restaurant sees when it is quoted.</div>
            </div>

            <div class="field">
                <label for="plan-code">Code</label>
                <input id="plan-code" type="text" name="code" class="form-control" required
                       value="{{ $plan->code }}" autocomplete="off" aria-invalid="false"
                       maxlength="40" placeholder="restaurant">
                <div class="form-hint">Short and permanent — quoted in support conversations.</div>
            </div>

            <div class="field field-full">
                <label for="plan-blurb">One line</label>
                <input id="plan-blurb" type="text" name="blurb" class="form-control"
                       value="{{ $plan->blurb }}" autocomplete="off" aria-invalid="false"
                       maxlength="255" placeholder="The full floor: tables, kitchen display, stock and purchasing.">
                <div class="form-hint">Who it is for, in the seller's own words.</div>
            </div>

            <div class="field">
                <label for="plan-slug">Slug</label>
                <input id="plan-slug" type="text" name="slug" class="form-control"
                       value="{{ $plan->slug }}" autocomplete="off" aria-invalid="false"
                       placeholder="{{ $isNew ? 'Filled in from the name' : $plan->slug }}"
                       data-slug-target>
            </div>

            <div class="field">
                <label for="plan-order">Display order</label>
                <input id="plan-order" type="number" name="sort_order" class="form-control"
                       value="{{ $plan->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
                <div class="form-hint">Lowest first, on the plan chooser.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Price</div>

        <div class="settings-grid">
            <div class="field">
                <label for="plan-monthly">Monthly price</label>
                <input id="plan-monthly" type="number" name="monthly_price" class="form-control" required
                       value="{{ $plan->monthly_price ?? 0 }}" min="0" step="0.01" aria-invalid="false">
            </div>

            <div class="field">
                <label for="plan-yearly">Yearly price</label>
                <input id="plan-yearly" type="number" name="yearly_price" class="form-control"
                       value="{{ $plan->yearly_price }}" min="0" step="0.01" aria-invalid="false"
                       placeholder="Twelve months">
                <div class="form-hint">
                    Leave empty and a year costs twelve months. Fill it in to give a discount.
                </div>
            </div>

            <div class="field">
                <label for="plan-currency">Currency</label>
                <input id="plan-currency" type="text" name="currency" class="form-control" required
                       value="{{ $plan->currency ?: 'INR' }}" maxlength="8" aria-invalid="false">
            </div>

            <div class="field">
                <label for="plan-trial">Free trial</label>
                <input id="plan-trial" type="number" name="trial_days" class="form-control"
                       value="{{ $plan->trial_days ?? 0 }}" min="0" max="365" aria-invalid="false">
                <div class="form-hint">Days. 0 for no trial.</div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">What it includes</div>

        <div class="field field-full">
            <label class="check">
                <input type="checkbox" name="modules_all" value="1" @checked($everything)>
                <span>Every module, including any added in future</span>
            </label>
            <div class="form-hint">
                While this is ticked the list below is ignored and nothing is stored — which is
                what keeps the plan up to date on its own. Untick it to sell a specific set.
            </div>
        </div>

        <div class="settings-grid" style="margin-top:10px">
            @foreach ($modules as $key => $module)
                <div class="field">
                    <label class="check">
                        <input type="checkbox" name="modules[]" value="{{ $key }}"
                               @checked(in_array($key, $selected, true))>
                        <span>{{ $module['label'] ?? $key }}</span>
                    </label>
                    @if (! empty($module['blurb']))
                        <div class="form-hint">{{ $module['blurb'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Limits</div>

        <div class="settings-grid">
            <div class="field">
                <label for="plan-shops">Branches</label>
                <input id="plan-shops" type="number" name="max_shops" class="form-control"
                       value="{{ $plan->max_shops }}" min="0" max="100000" aria-invalid="false"
                       placeholder="Unlimited">
                <div class="form-hint">Empty for unlimited. Checked when a branch is added.</div>
            </div>

            <div class="field">
                <label for="plan-users">Staff accounts</label>
                <input id="plan-users" type="number" name="max_users" class="form-control"
                       value="{{ $plan->max_users }}" min="0" max="100000" aria-invalid="false"
                       placeholder="Unlimited">
                <div class="form-hint">Empty for unlimited.</div>
            </div>

            <div class="field">
                <label for="plan-orders">Orders a month</label>
                <input id="plan-orders" type="number" name="max_orders_per_month" class="form-control"
                       value="{{ $plan->max_orders_per_month }}" min="0" aria-invalid="false"
                       placeholder="Unlimited">
                <div class="form-hint">
                    Empty for unlimited. Reported, never enforced mid-service — a kitchen is not
                    stopped from billing a table because a counter ticked over.
                </div>
            </div>
        </div>

        <div class="field field-full" style="margin-top:10px">
            <label class="check">
                <input type="checkbox" name="is_active" value="1" @checked($isNew ? true : $plan->is_active)>
                <span>On sale</span>
            </label>
            <div class="form-hint">
                Unticking withdraws it from sale. Businesses already on it keep it.
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create plan' : 'Save changes' }}
        </button>
    </div>
</form>
