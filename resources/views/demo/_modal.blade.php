{{--
    "Contact support" in a window, on the pages that carry a button for it. A native
    <dialog>: it traps focus, closes on Esc and dims the page behind it with no
    library. The same form as /demo (demo._form), with the same email code.
--}}
<dialog class="lp-modal" data-demo-modal aria-labelledby="demo-modal-title">
    <div class="lp-modal-head">
        <h2 id="demo-modal-title">Contact support</h2>
        <button type="button" class="lp-modal-close" data-demo-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="lp-modal-body lp-demo-form">
        @include('demo._form', ['idp' => 'm'])
    </div>
</dialog>
