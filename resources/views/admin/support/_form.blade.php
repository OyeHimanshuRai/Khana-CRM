{{--
    Raise a ticket, rendered straight into the modal body (§2).

    There is no company picker. The tenant is taken from the actor's own
    context in the controller and never from this form — see the note on
    SupportTicketController::store() for what a tenant_id in the payload would
    let somebody do.
--}}

<form method="POST" action="{{ route('admin.support.store') }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf

    <div class="settings-grid">
        <div class="field field-full">
            <label for="tk-subject">What is the problem</label>
            <input id="tk-subject" type="text" name="subject" class="form-control" required
                   maxlength="180" autocomplete="off" aria-invalid="false"
                   placeholder="Card machine is declining every UPI payment since this morning">
            <div class="form-hint">One line. The detail goes below.</div>
        </div>

        <div class="field">
            <label for="tk-category">Area</label>
            <select id="tk-category" name="category" class="form-control" required aria-invalid="false">
                @foreach ($categories as $key => $label)
                    <option value="{{ $key }}" @selected($key === 'other')>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label for="tk-priority">How urgent</label>
            <select id="tk-priority" name="priority" class="form-control" required aria-invalid="false">
                @foreach ($priorities as $key => $label)
                    <option value="{{ $key }}" @selected($key === App\Models\SupportTicket::NORMAL)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="form-hint">Urgent means you cannot trade.</div>
        </div>

        <div class="field field-full">
            <label for="tk-shop">Which branch</label>
            <select id="tk-shop" name="shop_id" class="form-control" aria-invalid="false">
                {{--
                    Optional, and the blank option is first: plenty of tickets
                    are about the account rather than a branch, and forcing a
                    choice would put a wrong one on half of them.
                --}}
                <option value="">Not about one branch</option>
                @foreach ($shops as $shop)
                    <option value="{{ $shop->id }}">{{ $shop->name }}</option>
                @endforeach
            </select>
            <div class="form-hint">Saves us asking "which outlet?" in the first reply.</div>
        </div>

        <div class="field field-full">
            <label for="tk-body">Tell us what happened</label>
            <textarea id="tk-body" name="body" class="form-control" rows="7" required
                      maxlength="10000" aria-invalid="false"
                      placeholder="What you did, what you expected, and what happened instead. Order numbers and times help."></textarea>
            {{--
                Said plainly rather than left to be discovered. A support thread
                is where people paste card numbers, and the ask is cheaper than
                the cleanup.
            --}}
            <div class="form-hint">Please do not paste card numbers or passwords — we never need them.</div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Raise ticket</button>
    </div>
</form>
