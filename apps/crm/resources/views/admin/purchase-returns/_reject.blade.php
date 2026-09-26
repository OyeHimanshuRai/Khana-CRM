{{-- Refuse a return, in the modal. --}}

<form method="POST" action="{{ route('admin.purchase-returns.reject', $return) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @method('PUT')

    <p class="text-sm">
        Refusing <strong>{{ $return->reference }}</strong> moves nothing: no stock leaves and the
        supplier is not credited. The document stays on the record with the reason below.
    </p>

    <div class="field" style="margin-top:14px">
        <label for="pr-reject-reason">Reason</label>
        <textarea id="pr-reject-reason" name="reason" class="form-control" required
                  style="min-height:70px" maxlength="250" aria-invalid="false"
                  placeholder="Already sold on, past the supplier's return window…"></textarea>
        <div class="form-hint">Kept with the return for whoever asks later.</div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Leave it pending</button>
        <button type="submit" class="btn btn-primary is-danger">Refuse the return</button>
    </div>
</form>
