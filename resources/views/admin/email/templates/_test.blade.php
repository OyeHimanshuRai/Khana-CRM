{{--
    Send one preview copy of a template, rendered into the modal body.

    Goes out through the same mailable a campaign uses, marked as a test, so
    it carries no tracking and appears in no campaign's report.
--}}

<form method="POST" action="{{ route('admin.email.templates.test.send', $template) }}"
      data-ajax data-close-modal>
    @csrf

    <div class="form-section">
        <div class="form-section-title">{{ $template->name }}</div>

        <div class="field">
            <label for="tpl-test-email">Send the test to</label>
            <input id="tpl-test-email" type="email" name="email" class="form-control"
                   value="{{ auth()->user()?->email }}" autocomplete="off" aria-invalid="false"
                   placeholder="you@example.com">
            <div class="form-hint">
                Arrives with <strong>[Test]</strong> on the subject, and the placeholders filled with
                sample values.
            </div>
        </div>

        <dl class="sec-facts" style="margin-top:14px">
            <div><dt>Subject</dt><dd style="font-size:13px">{{ $template->subject }}</dd></div>
            <div><dt>Handle</dt><dd class="list-ref" style="font-size:12px">{{ $template->slug }}</dd></div>
        </dl>

        @if (blank($template->content))
            <div class="form-hint" style="margin-top:12px;color:var(--danger)">
                This template has no content yet, so there is nothing to preview.
            </div>
        @endif
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary" @disabled(blank($template->content))>
            Send test
        </button>
    </div>
</form>
