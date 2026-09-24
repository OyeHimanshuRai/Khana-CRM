@extends('table.layout')

@section('title', 'How was it? · '.($shop?->name ?? $company))

@section('content')
    <header class="t-head">
        <div class="t-head-brand">{{ $shop?->name ?: $company }}</div>
        <h1 class="t-head-table">How was it?</h1>
        @if ($table)
            <p class="t-head-area">Table {{ $table->name }}</p>
        @endif
    </header>

    @include('table._flash')

    {{--
        Radio buttons, not stars.

        A star widget is a script, and this whole journey works without one.
        Five labelled radios are also the only version that a screen reader
        can read out and a thumb can hit reliably on a phone being held in one
        hand outside a restaurant.

        Only the overall rating is required. Every extra field asked for is a
        person who leaves without answering, and the ones who give up first
        are exactly the ones whose evening went badly.
    --}}
    {{--
        data-ajax gets the thank-you out immediately; the page then comes back
        to itself because the screen is drawn from the answer that was just
        saved - the button becomes "Update my answer", the radios come back
        ticked, and the line underneath names the rating. Leaving it standing
        with a toast on top would show somebody the answer they just replaced.
    --}}
    <form method="POST" action="{{ route('table.feedback.store') }}"
          data-ajax data-busy="Sending…">
        @csrf

        <section class="t-card">
            <fieldset class="t-group">
                <legend class="t-group-title">Overall</legend>

                <div class="t-choices">
                    @foreach ([5 => 'Very good', 4 => 'Good', 3 => 'All right', 2 => 'Poor', 1 => 'Bad'] as $value => $label)
                        <label class="t-opt">
                            <input type="radio" name="rating" value="{{ $value }}" required
                                   @checked(($existing->rating ?? null) === $value)>
                            <span class="t-opt-name">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </section>

        <section class="t-card">
            <fieldset class="t-group">
                <legend class="t-group-title">The food (optional)</legend>
                <div class="t-choices">
                    @foreach ([5 => 'Very good', 3 => 'All right', 1 => 'Bad'] as $value => $label)
                        <label class="t-opt">
                            <input type="radio" name="food_rating" value="{{ $value }}"
                                   @checked(($existing->food_rating ?? null) === $value)>
                            <span class="t-opt-name">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset class="t-group">
                <legend class="t-group-title">The service (optional)</legend>
                <div class="t-choices">
                    @foreach ([5 => 'Very good', 3 => 'All right', 1 => 'Bad'] as $value => $label)
                        <label class="t-opt">
                            <input type="radio" name="service_rating" value="{{ $value }}"
                                   @checked(($existing->service_rating ?? null) === $value)>
                            <span class="t-opt-name">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="t-group">
                <label class="t-group-title" for="fb-comment">Anything you would like to say?</label>
                <input id="fb-comment" type="text" name="comment" class="t-input"
                       maxlength="2000" autocomplete="off"
                       value="{{ $existing->comment ?? '' }}"
                       placeholder="The manager reads these">
            </div>

            <button type="submit" class="t-send">
                {{ $existing ? 'Update my answer' : 'Send' }}
            </button>

            @if ($existing)
                <p class="t-total-note">
                    You rated this {{ $existing->rating }} out of 5. Changing it replaces your
                    earlier answer rather than adding a second one.
                </p>
            @endif
        </section>
    </form>

    <nav class="t-nav">
        <a href="{{ route('table.orders') }}">Back to your order</a>
    </nav>
@endsection
