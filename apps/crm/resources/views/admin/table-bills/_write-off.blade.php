{{--
    Clear a table that left without paying.

    The only action on this screen that ends a sitting with money owed and no
    document raised, which is why it has a right of its own and why the reason
    is required. One nobody can explain a week later is how a shop stops
    trusting its own sales figures.
--}}

<form method="POST" action="{{ route('admin.table-bills.write-off', $session) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @method('PUT')

    @if ($summary['unbilled'] > 0)
        <p class="text-sm">
            Table {{ $session->table?->code }} owes
            <strong>₹{{ number_format($summary['unbilled'], 2) }}</strong>
            for {{ rtrim(rtrim(number_format($summary['items'], 3), '0'), '.') }} item(s).
            Clearing it writes that off — no bill is raised and nothing is taken from stock.
        </p>
    @else
        <p class="text-sm">
            Table {{ $session->table?->code }} owes nothing. Clearing it just frees the table.
        </p>
    @endif

    <div class="settings-grid" style="margin-top:10px">
        <div class="field field-full">
            <label for="wo-reason">Why</label>
            <input id="wo-reason" type="text" name="reason" class="form-control" required
                   autocomplete="off" aria-invalid="false" maxlength="190"
                   placeholder="Walked out without paying">
            <div class="form-hint">Required, and kept in the activity log against this table.</div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Clear the table</button>
    </div>
</form>
