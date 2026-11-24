@extends('table.layout')

@section('title', 'Receipt · Table '.($table?->name ?? ''))

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/css/table-receipt.css') }}?v={{ filemtime(public_path('assets/css/table-receipt.css')) }}">
@endpush

@section('content')
    <section class="t-card t-receipt" id="receipt">
        @include('table._receipt')
    </section>

    {{-- Not part of the receipt: hidden when it is printed. --}}
    <div class="t-rc-actions">
        <button type="button" class="t-add-btn" data-print>Print</button>
        <a class="t-add-btn t-rc-alt" href="{{ route('table.receipt.download') }}">Download Receipt</a>
    </div>

    <footer class="t-foot">
        <p><a href="{{ route('table.feedback') }}">How was it? Tell us &rarr;</a></p>
    </footer>

    @push('scripts')
        <script>
            document.querySelector('[data-print]').addEventListener('click', function () { window.print(); });
        </script>
    @endpush
@endsection
