{{-- Record / edit an expense, rendered straight into the modal body. --}}

@php
    $isNew = ! $expense->exists;
    $attachment = $isNew ? null : $expense->attachmentUrl();
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.expenses.store') : route('admin.expenses.update', $expense) }}"
      enctype="multipart/form-data"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    @if ($isNew && $reference)
        <p class="text-xs text-muted" style="margin:0 0 12px">
            Will be recorded as <span class="list-ref">{{ $reference }}</span>. Nothing is treated as
            money out until somebody approves it.
        </p>
    @endif

    <div class="settings-grid">
        <div class="field field-full">
            <label for="exp-title">What for</label>
            <input id="exp-title" type="text" name="title" class="form-control" required
                   value="{{ $expense->title }}" autocomplete="off" aria-invalid="false"
                   placeholder="September electricity, van diesel, godown rent…">
        </div>

        <div class="field">
            <label for="exp-amount">Amount (₹)</label>
            <input id="exp-amount" type="number" name="amount" class="form-control" required
                   value="{{ $expense->exists ? (float) $expense->amount : '' }}"
                   min="0.01" step="0.01" aria-invalid="false">
        </div>

        <div class="field">
            <label for="exp-date">Spent on</label>
            <input id="exp-date" type="date" name="spent_on" class="form-control" required
                   value="{{ $expense->spent_on?->toDateString() ?? today()->toDateString() }}"
                   max="{{ today()->toDateString() }}" aria-invalid="false">
        </div>

        <div class="field">
            <label for="exp-category">Category</label>
            <select id="exp-category" name="expense_category_id" class="form-control" aria-invalid="false">
                <option value="">Uncategorised</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}"
                        @selected($expense->expense_category_id === $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
            <div class="form-hint">Used to group the spending on reports.</div>
        </div>

        <div class="field">
            <label for="exp-payee">Paid to</label>
            <input id="exp-payee" type="text" name="paid_to" class="form-control"
                   value="{{ $expense->paid_to }}" autocomplete="off" aria-invalid="false">
        </div>

        <div class="field">
            <label for="exp-method">Paid by</label>
            <select id="exp-method" name="method" class="form-control" required aria-invalid="false">
                @foreach ($methods as $key => $meta)
                    <option value="{{ $key }}" @selected(($expense->method ?? 'cash') === $key)>
                        {{ $meta['label'] }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="exp-ref">Reference</label>
            <input id="exp-ref" type="text" name="transaction_ref" class="form-control"
                   value="{{ $expense->transaction_ref }}" autocomplete="off" aria-invalid="false"
                   maxlength="120" placeholder="UPI ref, cheque number">
        </div>

        <div class="field field-full">
            <label for="exp-notes">Notes</label>
            <textarea id="exp-notes" name="notes" class="form-control"
                      style="min-height:56px" aria-invalid="false">{{ $expense->notes }}</textarea>
        </div>

        <div class="field field-full">
            <label for="exp-attachment">Bill or receipt</label>
            <input id="exp-attachment" type="file" name="attachment"
                   accept="image/jpeg,image/png,image/webp,application/pdf">
            <div class="form-hint">
                A photograph or a PDF, up to 5&nbsp;MB.
                @if ($attachment)
                    <a href="{{ $attachment }}" target="_blank" rel="noopener">
                        The current one is attached.
                    </a>
                    Uploading a new file replaces it.
                @else
                    Without one, an expense is somebody's word.
                @endif
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Record expense' : 'Save changes' }}
        </button>
    </div>
</form>
