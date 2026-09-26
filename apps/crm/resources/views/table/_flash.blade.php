{{--
    What just happened, said once.

    Two keys rather than Laravel's generic `status`, because the two read very
    differently to a hungry guest: "added" can be glanced at, "that sold out"
    has to be noticed. Errors carry role="alert" so a screen reader interrupts
    for them and does not for the other.
--}}

@if (session('table_error'))
    <div class="t-flash is-error" role="alert">
        {{ session('table_error') }}
    </div>
@endif

@if (session('table_notice'))
    <div class="t-flash" role="status">
        {{ session('table_notice') }}
    </div>
@endif

{{--
    Validation errors from the cart forms. Rendered as one line rather than a
    list under a field: the forms are a radio group and a number box, and the
    guest needs to know what to change, not which input name failed.
--}}
@if ($errors->any())
    <div class="t-flash is-error" role="alert">
        {{ $errors->first() }}
    </div>
@endif
