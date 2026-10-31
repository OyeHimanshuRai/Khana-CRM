{{--
    Add / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The submit, the toasts and the inline field errors all come from
    app.js; data-close-modal and data-refresh-list are what dismiss the
    dialog and bring the listing behind it up to date.
--}}

@php $isNew = ! $faq->exists; @endphp

<form method="POST"
      action="{{ $isNew ? route('admin.faqs.store') : route('admin.faqs.update', $faq) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field field-full">
            <label for="faq-question">Question</label>
            <textarea id="faq-question" name="question" class="form-control" required
                      style="min-height:58px" maxlength="300"
                      aria-invalid="false">{{ $faq->question }}</textarea>
        </div>

        <div class="field field-full">
            <label for="faq-answer">Answer</label>
            <textarea id="faq-answer" name="answer" class="form-control" required
                      style="min-height:150px" aria-invalid="false">{{ $faq->answer }}</textarea>
            <div class="form-hint">Line breaks are kept; other formatting is not.</div>
        </div>

        <div class="field">
            <label for="faq-category">Category</label>
            {{--
                Free text with a datalist rather than a select: the labels
                that already exist are one keystroke away, but a new one
                needs no trip to a separate screen to create first.
            --}}
            <input id="faq-category" type="text" name="category" class="form-control"
                   value="{{ $faq->category }}" list="faq-category-options"
                   placeholder="Billing, Shipping…" autocomplete="off" aria-invalid="false">
            <datalist id="faq-category-options">
                @foreach ($categories as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <div class="form-hint">Leave blank to file it under “Uncategorised”.</div>
        </div>

        <div class="field">
            <label for="faq-order">Display order</label>
            <input id="faq-order" type="number" name="sort_order" class="form-control"
                   value="{{ $faq->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            <div class="form-hint">Lower numbers appear first within a category.</div>
        </div>

        <div class="field field-full">
            <div class="form-label">Status</div>
            <label class="check">
                {{-- The hidden field is what makes an unticked box submit a 0
                     rather than nothing at all. --}}
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $faq->is_active)>
                Active
            </label>
            <div class="form-hint">Inactive FAQs stay in this list but are hidden elsewhere.</div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create FAQ' : 'Save changes' }}
        </button>
    </div>
</form>
