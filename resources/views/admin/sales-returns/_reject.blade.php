{{-- Refuse a return, in the modal. --}}

<form method="POST" action="{{ route('admin.sales-returns.reject', $return) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @method('PUT')

    <p class="text-sm">
        Refusing <strong>{{ $return->reference }}</strong> moves nothing: no stock comes back and
        the customer is not credited. The document stays on the record with the reason below.
    </p>

    <div class="field" style="margin-top:14px">
        <label for="sr-reject-reason">Reason</label>
        <textarea id="sr-reject-reason" name="reason" class="form-control" required
                  style="min-height:70px" maxlength="250" aria-invalid="false"
                  placeholder="Outside the return window, packaging opened, not sold by us…"></textarea>
        <div class="form-hint">The customer will be told this, so write it for them.</div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Leave it pending</button>
        <button type="submit" class="btn btn-primary is-danger">Refuse the return</button>
    </div>
</form>
