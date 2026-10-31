{{--
    One demo request (§19), rendered into the modal body.

    Nothing here edits what the enquirer typed. The only writes are the status
    and an internal note — what somebody said they wanted is a record, and a
    lead whose details can be quietly rewritten is no longer evidence of
    anything.
--}}

<dl class="sec-facts">
    <div>
        <dt>Name</dt>
        <dd>{{ $row->name }}</dd>
    </div>

    <div>
        <dt>Business</dt>
        <dd>{{ $row->business_name ?: '—' }}</dd>
    </div>

    <div>
        <dt>City</dt>
        <dd>{{ $row->city ?: '—' }}</dd>
    </div>

    <div>
        <dt>Outlets</dt>
        <dd>{{ $row->outlets ?: '—' }}</dd>
    </div>

    <div>
        <dt>Phone</dt>
        <dd>
            @if ($row->phone)
                <a href="tel:{{ preg_replace('/\s+/', '', $row->phone) }}">{{ $row->phone }}</a>
            @else
                —
            @endif
        </dd>
    </div>

    <div>
        <dt>Email</dt>
        <dd>
            @if ($row->email)
                <a href="mailto:{{ $row->email }}">{{ $row->email }}</a>
            @else
                —
            @endif
        </dd>
    </div>

    <div>
        <dt>Received</dt>
        <dd>{{ $row->created_at?->format('j M Y, g:i a') }}</dd>
    </div>

    <div>
        <dt>Handled by</dt>
        <dd>{{ $row->handler?->name ?? 'Nobody yet' }}</dd>
    </div>
</dl>

@if ($row->message)
    <div style="margin-top:18px">
        <div class="form-label">What they said</div>
        <p class="text-sm" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $row->message }}</p>
    </div>
@endif

@allows('content.demo_requests.edit')
    <hr>

    <form method="POST" action="{{ route('admin.demo-requests.update', $row) }}"
          data-ajax data-close-modal data-refresh-list>
        @csrf
        @method('PUT')

        <div class="settings-grid">
            <div class="field">
                <label for="dr-set-status">Status</label>
                <select id="dr-set-status" name="status" class="form-control" required aria-invalid="false">
                    @foreach ($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($row->status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field field-full">
                <label for="dr-note">Internal note</label>
                <textarea id="dr-note" name="note" class="form-control" rows="3"
                          maxlength="2000" aria-invalid="false"
                          placeholder="Rang Tuesday — wants a callback after 6pm.">{{ $row->note }}</textarea>
                {{-- Never shown to the enquirer; there is no screen that would
                     show it to them. --}}
                <div class="form-hint">Only ever seen here.</div>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Close</button>
            <button type="submit" class="btn btn-primary">Save</button>
        </div>
    </form>
@else
    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Close</button>
    </div>
@endallows
