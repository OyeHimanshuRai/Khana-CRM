{{--
    Send a ticket back down the ladder (§9 - reopen).

    Only the stages behind it are offered. Offering the rung it is already on,
    or one ahead, would make this a second and unlogged way to bump - which is
    the whole thing the separate permission exists to prevent.
--}}

<form method="POST" action="{{ route('admin.kitchen.recall', $order) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @method('PUT')

    @if ($station)
        <input type="hidden" name="station_id" value="{{ $station->id }}">
    @endif

    @if (empty($stages))
        <p class="text-sm">
            {{ $order->order_number }} has not been started yet — there is nothing to send it back to.
        </p>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Close</button>
        </div>
    @else
        <div class="settings-grid">
            <div class="field field-full">
                <label for="kr-to">Send it back to</label>
                <select id="kr-to" name="to" class="form-control" required aria-invalid="false">
                    @foreach (array_reverse($stages) as $stage)
                        <option value="{{ $stage }}">{{ $labels[$stage] ?? $stage }}</option>
                    @endforeach
                </select>
                <div class="form-hint">
                    @if ($station)
                        Only {{ $station->name }}'s lines on this ticket move.
                    @else
                        Every line on this ticket moves.
                    @endif
                </div>
            </div>

            <div class="field field-full">
                <label for="kr-reason">Why</label>
                <input id="kr-reason" type="text" name="reason" class="form-control" required
                       autocomplete="off" aria-invalid="false" maxlength="190"
                       placeholder="Plate came back — under-seasoned">
                <div class="form-hint">
                    Required. This rewrites the times the preparation report is built
                    from, and a recall nobody can explain next week is how that report
                    stops being trusted.
                </div>
            </div>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Send it back</button>
        </div>
    @endif
</form>
