{{--
    Add / Edit printer, rendered into the modal body.

    No <script> here — markup injected via innerHTML never runs its scripts,
    so the network fields are always visible rather than revealed by a driver
    picker. The hints carry the conditionality instead, and the server refuses
    a network printer with no address.
--}}

@php
    $isNew = ! $printer->exists;
@endphp

<form method="POST"
      action="{{ $isNew ? route('admin.printers.store') : route('admin.printers.update', $printer) }}"
      data-ajax data-close-modal data-refresh-list>
    @csrf
    @unless ($isNew) @method('PUT') @endunless

    <div class="form-section">
        <div class="form-section-title">What it is</div>

        <div class="settings-grid">
            <div class="field">
                <label for="pr-name">Name</label>
                <input id="pr-name" type="text" name="name" class="form-control" required
                       value="{{ $printer->name }}" maxlength="90" autocomplete="off" aria-invalid="false"
                       placeholder="Tandoor printer">
                <div class="form-hint">What staff call it. Where it is, usually.</div>
            </div>

            <div class="field">
                <label for="pr-code">Code</label>
                <input id="pr-code" type="text" name="code" class="form-control" required
                       value="{{ $printer->code }}" maxlength="40" autocomplete="off" aria-invalid="false"
                       placeholder="tandoor">
            </div>

            <div class="field">
                <label for="pr-kind">Prints</label>
                <select id="pr-kind" name="kind" class="form-control" required aria-invalid="false">
                    @foreach (App\Models\Printer::KINDS as $value => $label)
                        <option value="{{ $value }}" @selected(($printer->kind ?? 'kot') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="pr-station">Kitchen station</label>
                <select id="pr-station" name="kitchen_station_id" class="form-control" aria-invalid="false">
                    <option value="">Not tied to one</option>
                    @foreach ($stations as $station)
                        <option value="{{ $station->id }}" @selected($printer->kitchen_station_id === $station->id)>
                            {{ $station->name }}
                        </option>
                    @endforeach
                </select>
                <div class="form-hint">
                    Only used for kitchen tickets. A ticket split across a tandoor and a bar prints
                    twice, each copy carrying only its own station's dishes.
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">How it prints</div>

        <div class="settings-grid">
            <div class="field field-full">
                <label for="pr-driver">Driver</label>
                <select id="pr-driver" name="driver" class="form-control" required aria-invalid="false">
                    @foreach (App\Models\Printer::DRIVERS as $value => $label)
                        <option value="{{ $value }}" @selected(($printer->driver ?? 'browser') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="form-hint">
                    <strong>Browser</strong> is what this system has always done: a print page and your
                    operating system's dialog. Fine for a bill handed over by the person who pressed
                    the button.
                    <strong>Network</strong> opens a socket to the printer and sends it the ticket —
                    which is the only way paper appears beside the tandoor while the till is at the
                    front of the shop.
                </div>
            </div>

            <div class="field">
                <label for="pr-host">Address</label>
                <input id="pr-host" type="text" name="host" class="form-control"
                       value="{{ $printer->host }}" maxlength="120" autocomplete="off" aria-invalid="false"
                       placeholder="192.168.1.50">
                <div class="form-hint">
                    Required for a network printer. Give it a fixed address on your router — a printer
                    whose IP changes overnight is a kitchen with no tickets in the morning.
                </div>
            </div>

            <div class="field">
                <label for="pr-port">Port</label>
                <input id="pr-port" type="number" name="port" class="form-control"
                       value="{{ $printer->port ?? 9100 }}" min="1" max="65535" aria-invalid="false">
                <div class="form-hint">9100 unless you know otherwise. Almost nobody changes it.</div>
            </div>

            <div class="field">
                <label for="pr-columns">Characters per line</label>
                <select id="pr-columns" name="columns" class="form-control" required aria-invalid="false">
                    <option value="32" @selected(($printer->columns ?? 42) === 32)>32 — 58mm paper</option>
                    <option value="42" @selected(($printer->columns ?? 42) === 42)>42 — 80mm paper</option>
                    <option value="48" @selected(($printer->columns ?? 42) === 48)>48 — 80mm, small font</option>
                </select>
                <div class="form-hint">
                    If lines wrap oddly or prices fall off the edge, this is the setting that is wrong.
                </div>
            </div>

            <div class="field">
                <label for="pr-copies">Copies</label>
                <input id="pr-copies" type="number" name="copies" class="form-control" required
                       value="{{ $printer->copies ?? 1 }}" min="1" max="5" aria-invalid="false">
            </div>
        </div>

        <div class="settings-grid" style="margin-top:10px">
            <div class="field">
                <label class="check">
                    <input type="checkbox" name="auto_cut" value="1" @checked($isNew ? true : $printer->auto_cut)>
                    <span>Cut the paper after printing</span>
                </label>
                <div class="form-hint">Untick for a printer with no cutter — the command prints as gibberish instead.</div>
            </div>

            <div class="field">
                <label class="check">
                    <input type="checkbox" name="is_default" value="1" @checked($printer->is_default)>
                    <span>Default for this kind</span>
                </label>
                <div class="form-hint">Used when nothing more specific matches. One per kind.</div>
            </div>

            <div class="field">
                <label class="check">
                    <input type="checkbox" name="is_active" value="1" @checked($isNew ? true : $printer->is_active)>
                    <span>In use</span>
                </label>
            </div>

            <div class="field field-full">
                <label for="pr-notes">Notes</label>
                <input id="pr-notes" type="text" name="notes" class="form-control"
                       value="{{ $printer->notes }}" maxlength="190" aria-invalid="false"
                       placeholder="Behind the pass, on the shelf above the tandoor">
            </div>
        </div>
    </div>

    <div class="modal-actions">
        <button type="button" class="btn" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">
            {{ $isNew ? 'Add printer' : 'Save changes' }}
        </button>
    </div>
</form>
