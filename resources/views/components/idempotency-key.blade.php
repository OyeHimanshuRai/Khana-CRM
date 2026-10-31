{{--
    Names this rendering of a form, so submitting it twice (a double tap, a retry
    after a lost answer) is one request and not two. Read by the `idempotent`
    middleware; see App\Http\Middleware\EnsureIdempotent. Put it inside any
    plain (non-ajax) POST form whose route carries that middleware - an ajax form
    does the same through app.js and needs nothing.
--}}
<input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::random(32) }}">
