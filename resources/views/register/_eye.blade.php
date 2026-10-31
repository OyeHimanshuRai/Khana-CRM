{{--
    Show/hide for a password box, as an eye. app.js flips the input's type and
    toggles .is-revealed, which swaps the two icons (see register.css).

    @param string $for  the input's id
--}}
<button type="button" class="rg-reveal" data-toggle-password data-toggle-icon
        aria-controls="{{ $for }}" aria-pressed="false" aria-label="Show password">
    <svg class="rg-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
        <circle cx="12" cy="12" r="3"/>
    </svg>
    <svg class="rg-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M3 3l18 18"/>
        <path d="M10.6 5.1A9.6 9.6 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.9 8.4 2 12 2 12s3.6 7 10 7a9.4 9.4 0 0 0 5.4-1.6"/>
        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
    </svg>
</button>
