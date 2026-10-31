@extends('table.layout')

@section('title', $heading)

@section('content')
    <header class="t-head">
        <div class="t-head-brand">{{ $company }}</div>
    </header>

    <section class="t-card t-notice">
        <h1>{{ $heading }}</h1>
        <p>{{ $body }}</p>
        @isset($link)
            <p><a class="t-send" href="{{ $link[0] }}" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none">{{ $link[1] }}</a></p>
        @endisset
    </section>
@endsection
