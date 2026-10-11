<footer class="app-footer">
    <span>&copy; {{ date('Y') }} Khana. All rights reserved.</span>
    <span>
        Khana {{ Khana\Kit\Ui\Ui::productName() }}
        @php $help = config('khana.urls.accounts') ? rtrim((string) config('khana.urls.accounts'), '/').'/help' : null; @endphp
        @if ($help)
            &middot; <a href="{{ $help }}">Help</a>
        @endif
    </span>
</footer>
