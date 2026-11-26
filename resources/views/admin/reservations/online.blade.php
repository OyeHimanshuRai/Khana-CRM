@extends('admin.layouts.app')

@section('title', 'Online Booking')

@section('content')
    <x-page-header
        title="Online Booking"
        subtitle="Let guests book a table from your online shop"
        :crumbs="['Tables & QR' => null, 'Reservations' => route('admin.reservations.index'), 'Online Booking' => null]"
    />

    @if (! $shop)
        <div class="alert alert-info">Online booking is set per branch. Pick a branch from the switcher at the top first.</div>
    @else
        @if (! $storefront)
            <div class="alert alert-warning">This branch's online shop is switched off, so guests cannot reach the booking page. Turn on the Online storefront module first.</div>
        @endif
        @if (! $onlinePayment)
            <div class="alert alert-info">Online payment is not set up, so you can take bookings without an advance only.</div>
        @endif
        @if ($bookUrl && $settings['enabled'])
            <div class="alert alert-success">Guests book here: <a href="{{ $bookUrl }}" target="_blank" rel="noopener">{{ $bookUrl }}</a></div>
        @endif

        <div class="card">
            <form method="POST" action="{{ route('admin.reservations.online.update') }}" data-ajax class="card-body">
                @csrf
                @method('PUT')

                <div class="form-section">
                    <label class="check">
                        <input type="hidden" name="enabled" value="0">
                        <input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])>
                        <span><strong>Take bookings online</strong> for {{ $shop->name }}</span>
                    </label>
                </div>

                <div class="form-section">
                    <div class="form-section-title">When</div>
                    <div class="settings-grid">
                        <div class="field">
                            <label for="ob-opens">First booking time</label>
                            <input id="ob-opens" type="time" name="opens" class="form-control" value="{{ $settings['opens'] }}" required>
                        </div>
                        <div class="field">
                            <label for="ob-closes">Last booking time</label>
                            <input id="ob-closes" type="time" name="closes" class="form-control" value="{{ $settings['closes'] }}" required>
                        </div>
                        <div class="field">
                            <label for="ob-slot">Times every</label>
                            <select id="ob-slot" name="slot_minutes" class="form-control">
                                @foreach ([15, 30, 45, 60] as $m)
                                    <option value="{{ $m }}" @selected((int) $settings['slot_minutes'] === $m)>{{ $m }} minutes</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field">
                            <label for="ob-duration">A table is kept for (minutes)</label>
                            <input id="ob-duration" type="number" name="duration_minutes" class="form-control" min="30" max="300" step="15" value="{{ $settings['duration_minutes'] }}" required>
                        </div>
                        <div class="field">
                            <label for="ob-lead">Book at least (minutes ahead)</label>
                            <input id="ob-lead" type="number" name="lead_minutes" class="form-control" min="0" max="1440" value="{{ $settings['lead_minutes'] }}" required>
                        </div>
                        <div class="field">
                            <label for="ob-window">Book up to (days ahead)</label>
                            <input id="ob-window" type="number" name="window_days" class="form-control" min="1" max="180" value="{{ $settings['window_days'] }}" required>
                        </div>
                    </div>
                    <div class="field" style="margin-top:12px;">
                        <label>Open for bookings on</label>
                        <div style="display:flex;flex-wrap:wrap;gap:12px;">
                            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $day)
                                <label class="check" style="margin:0;">
                                    <input type="checkbox" name="days[]" value="{{ $i }}" @checked(in_array($i, array_map('intval', $settings['days']), true))> {{ $day }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">Guests & advance</div>
                    <div class="settings-grid">
                        <div class="field">
                            <label for="ob-party">Most guests per online booking</label>
                            <input id="ob-party" type="number" name="max_party" class="form-control" min="1" max="50" value="{{ $settings['max_party'] }}" required>
                            <div class="form-hint">Bigger groups are asked to call.</div>
                        </div>
                        <div class="field">
                            <label for="ob-mode">Advance</label>
                            <select id="ob-mode" name="deposit_mode" class="form-control">
                                <option value="none" @selected($settings['deposit_mode'] === 'none')>No advance (confirmed at once)</option>
                                <option value="fixed" @selected($settings['deposit_mode'] === 'fixed')>Fixed amount per booking</option>
                                <option value="per_person" @selected($settings['deposit_mode'] === 'per_person')>Amount per guest</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="ob-amount">Advance amount (₹)</label>
                            <input id="ob-amount" type="number" name="deposit_amount" class="form-control" min="0" step="1" value="{{ (float) $settings['deposit_amount'] }}">
                        </div>
                        <div class="field">
                            <label for="ob-hold">Table held while paying (minutes)</label>
                            <input id="ob-hold" type="number" name="hold_minutes" class="form-control" min="5" max="30" value="{{ $settings['hold_minutes'] }}" required>
                        </div>
                        <div class="field">
                            <label for="ob-cancel">Free cancellation up to (hours before)</label>
                            <input id="ob-cancel" type="number" name="cancel_hours" class="form-control" min="0" max="168" value="{{ $settings['cancel_hours'] }}" required>
                            <div class="form-hint">Cancelled in time: the advance is refunded in full. A no-show keeps it.</div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div>
    @endif
@endsection
