@extends('table.layout')

@section('title', $heading)

@section('content')
    <header class="t-head">
        <div class="t-head-brand">{{ $company }}</div>
    </header>

    <section class="t-card t-notice">
        <h1>{{ $heading }}</h1>
        <p>{{ $body }}</p>
    </section>
@endsection
