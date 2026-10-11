@props(['product' => 'ERP'])

{{--
    One panel that reads another service, when that service is down. The rest
    of the screen keeps working; only this card says so.
--}}
<div {{ $attributes->class(['alert', 'alert-warning']) }} role="status">
    <x-khana::icon name="alert" :size="16" />
    <span>{{ $product }} is not reachable right now. This panel will fill in once it is back; everything else on this screen works.</span>
</div>
