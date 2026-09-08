{{-- Inline stub first: it installs the handlers and queues events synchronously, so
     errors thrown before the async bundle arrives are still caught — those are the ones
     that matter. Config is rendered server-side so nothing is duplicated into JS. --}}
@php($nonce = \Illuminate\Support\Facades\Vite::cspNonce())
<script @if($nonce) nonce="{{ $nonce }}" @endif>
window.__erConfig = @json($config);
{!! $stub !!}
</script>
@if (filled($sdkUrl))
<script src="{{ $sdkUrl }}" async crossorigin="anonymous" @if($nonce) nonce="{{ $nonce }}" @endif></script>
@endif
