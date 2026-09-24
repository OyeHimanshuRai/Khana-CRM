{{--
    Bulk menu import (§8).

    Two buttons, because anybody sane checks four hundred rows before writing
    them: Preview validates and reports without changing anything, Import does
    it for real.

    One bad row stops the whole file. A half-imported menu is worse than none —
    the operator cannot tell which rows landed, re-running would double what
    did, and the only way back is to delete four hundred dishes by hand.
--}}

<form method="POST" action="{{ route('admin.products.import') }}"
      enctype="multipart/form-data" data-ajax data-refresh-list>
    @csrf

    <div class="settings-grid">
        <div class="field field-full">
            <label for="mi-file">Menu file</label>
            <input id="mi-file" type="file" name="file" class="form-control" required
                   accept=".csv,text/csv" aria-invalid="false">
            <div class="form-hint">
                A .csv file, up to {{ number_format($maxRows) }} rows. Save it as CSV
                from Excel — an .xlsx will not open.
            </div>
        </div>

        <div class="field field-full">
            <a class="btn btn-sm" href="{{ route('admin.products.template') }}">
                <x-icon name="download" :size="14" /> Download the template
            </a>
            <div class="form-hint">
                It carries one real dish from this menu as an example, so nobody has
                to guess what "Food Type" wants.
            </div>
        </div>

        <div class="field field-full">
            <div class="form-label">Columns it reads</div>
            <p class="text-xs text-muted" style="margin:0">
                {{ implode(' · ', $columns) }}
            </p>
            <div class="form-hint">
                Order does not matter and spare columns are ignored. Only <strong>Name</strong>
                is required, and a new dish also needs a <strong>Unit</strong>.
            </div>
        </div>

        <div class="field field-full">
            <div class="form-label">What it does with each row</div>
            <ul class="text-sm" style="margin:0; padding-left:18px">
                <li>Matched on <strong>SKU</strong>, or on the name when there is no SKU.</li>
                <li>A blank cell leaves that field alone — it does not clear it.</li>
                <li>Categories that do not exist are created, and named back to you.</li>
                <li>Stock is never imported. It moves through receipts and adjustments,
                    which is the only way it stays reconcilable.</li>
            </ul>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>

        {{-- Preview posts the same form with a flag, so the two can never
             disagree about what the file says. --}}
        <button type="submit" class="btn" name="preview" value="1">Preview</button>
        <button type="submit" class="btn btn-primary">Import</button>
    </div>
</form>
