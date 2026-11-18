@extends('admin.layouts.app')

@section('title', $title)

@section('content')
    {{-- A dialog screen opened on its own: see App\Http\Middleware\WrapModalFragment. --}}
    <x-page-header :title="$title" :crumbs="[$title => null]">
        <x-slot:actions>
            <a class="btn btn-ghost btn-sm" href="{{ $backUrl }}">Back to list</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card" data-fragment-page>
        <div class="card-body" style="max-width:760px">{!! $html !!}</div>
    </div>

    <script>
        document.querySelectorAll('[data-fragment-page] [data-modal-close]').forEach(function (b) {
            b.addEventListener('click', function (e) { e.preventDefault(); location.href = @json($backUrl); });
        });
    </script>
@endsection
