{{--
    Add / Edit form, rendered straight into the modal body. No <script> here;
    app.js handles the submit, toasts and inline errors.
--}}

@php $isNew = ! $page->exists; @endphp

<form method="POST"
      action="{{ $isNew ? route('admin.pages.store') : route('admin.pages.update', $page) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="settings-grid">
        <div class="field">
            <label for="page-title">Title</label>
            <input id="page-title" type="text" name="title" class="form-control" required
                   maxlength="160" value="{{ $page->title }}" placeholder="Privacy Policy" aria-invalid="false">
        </div>

        <div class="field">
            <label for="page-slug">Address</label>
            <input id="page-slug" type="text" name="slug" class="form-control" maxlength="120"
                   value="{{ $page->slug }}" placeholder="privacy-policy" autocomplete="off" aria-invalid="false">
            <div class="form-hint">Lowercase letters, numbers and hyphens. Blank = made from the title.</div>
        </div>

        <div class="field field-full">
            <label for="page-body">Content</label>
            <textarea id="page-body" name="body" class="form-control" required
                      style="min-height:260px" aria-invalid="false">{{ $page->body }}</textarea>
            <div class="form-hint">
                Markdown: <code>## Heading</code>, <code>- list item</code>, <code>**bold**</code>,
                <code>[text](https://…)</code>. Raw HTML is removed.
            </div>
        </div>

        <div class="field field-full">
            <label for="page-meta">Search description <span class="text-muted">(optional)</span></label>
            <input id="page-meta" type="text" name="meta_description" class="form-control" maxlength="300"
                   value="{{ $page->meta_description }}" aria-invalid="false">
        </div>

        <div class="field">
            <label for="page-order">Display order</label>
            <input id="page-order" type="number" name="sort_order" class="form-control"
                   value="{{ $page->sort_order ?? 0 }}" min="0" max="65535" aria-invalid="false">
            <div class="form-hint">Lower numbers come first in the footer.</div>
        </div>

        <div class="field">
            <div class="form-label">Options</div>
            <label class="check">
                <input type="hidden" name="show_in_footer" value="0">
                <input type="checkbox" name="show_in_footer" value="1"
                       @checked($isNew ? true : $page->show_in_footer)>
                Link from the footer
            </label>
            <label class="check">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked($isNew ? true : $page->is_active)>
                Published
            </label>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">{{ $isNew ? 'Create page' : 'Save changes' }}</button>
    </div>
</form>
