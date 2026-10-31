{{--
    Create / Edit form, rendered straight into the modal body.

    No <script> may live here - markup injected via innerHTML never runs its
    scripts. The merge-tag chips are delegated from index.blade.php; the
    submit, the toasts and the inline field errors come from app.js.
--}}

@php $isNew = ! $template->exists; @endphp

<form method="POST"
      action="{{ $isNew
          ? route('admin.email.templates.store')
          : route('admin.email.templates.update', $template) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">Template</div>

        <div class="settings-grid">
            <div class="field">
                <label for="tpl-name">Name <span aria-hidden="true">*</span></label>
                <input id="tpl-name" type="text" name="name" class="form-control" required
                       value="{{ $template->name }}" autocomplete="off" aria-invalid="false"
                       placeholder="Order confirmation">
            </div>

            <div class="field">
                <label for="tpl-slug">Handle</label>
                <input id="tpl-slug" type="text" name="slug" class="form-control"
                       value="{{ $template->slug }}" autocomplete="off" aria-invalid="false"
                       placeholder="order-confirmation">
                <div class="form-hint">
                    Leave blank to derive it from the name. Lowercase letters, numbers and hyphens.
                </div>
            </div>

            <div class="field">
                <label for="tpl-category">Category</label>
                <input id="tpl-category" type="text" name="category" class="form-control"
                       value="{{ $template->category }}" autocomplete="off" aria-invalid="false"
                       list="tpl-categories" placeholder="Transactional">
                {{-- Suggests what is already in use without stopping a new one. --}}
                <datalist id="tpl-categories">
                    @foreach ($categories as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>
            </div>

            <div class="field">
                <div class="form-label">Status</div>
                <label class="check">
                    {{-- The hidden field is what makes an unticked box submit
                         a 0 rather than nothing at all. --}}
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked($isNew ? true : $template->is_active)>
                    Active
                </label>
                <div class="form-hint">Inactive templates stay out of the campaign picker.</div>
            </div>

            <div class="field field-full">
                <label for="tpl-description">Description</label>
                <input id="tpl-description" type="text" name="description" class="form-control"
                       value="{{ $template->description }}" autocomplete="off" aria-invalid="false"
                       placeholder="What this one is for, and when to reach for it.">
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">Content</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="tpl-subject">Subject <span aria-hidden="true">*</span></label>
                <input id="tpl-subject" type="text" name="subject" class="form-control" required
                       value="{{ $template->subject }}" autocomplete="off" aria-invalid="false"
                       placeholder="What's new this month">
            </div>

            <div class="field field-full">
                <label for="tpl-preheader">Preheader</label>
                <input id="tpl-preheader" type="text" name="preheader" class="form-control"
                       value="{{ $template->preheader }}" autocomplete="off" aria-invalid="false"
                       placeholder="A line the client shows next to the subject.">
            </div>
        </div>

        <div class="field">
            <label for="tpl-content">Email body (HTML)</label>
            <textarea id="tpl-content" name="content" class="form-control" rows="14"
                      data-tag-into aria-invalid="false" spellcheck="false"
                      style="font-family:ui-monospace,Consolas,monospace;font-size:12.5px"
                      {{-- @{{ is Blade's escape for a literal brace pair. --}}
                      placeholder="&lt;p&gt;Hello @{{name}},&lt;/p&gt;">{{ $template->content }}</textarea>
            <div class="form-hint">
                Wrapped in the site's email template automatically - header, footer, postal address
                and unsubscribe link are all added for you.
            </div>
        </div>

        <div class="form-hint" style="margin-top:12px">
            <strong>Placeholders</strong> — click to insert at the cursor.
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
                @foreach ($mergeTags as $tag => $meaning)
                    <button type="button" class="badge" data-insert-tag="{{ $tag }}"
                            title="{{ $meaning }}"
                            style="cursor:pointer;border:0;font:inherit">
                        {{ $tag }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @unless ($isNew)
        <div class="form-section">
            <div class="form-section-title">Usage</div>

            <dl class="sec-facts">
                <div><dt>Campaigns started</dt><dd>{{ number_format($template->usage_count) }}</dd></div>
                <div>
                    <dt>Last used</dt>
                    <dd>{{ $template->last_used_at?->format('d M Y') ?? '—' }}</dd>
                </div>
            </dl>

            <div class="form-hint" style="margin-top:10px">
                Editing this template does not change any campaign already started from it — the
                content was copied, not linked.
            </div>
        </div>
    @endunless

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Create template' : 'Save changes' }}
        </button>
    </div>
</form>
