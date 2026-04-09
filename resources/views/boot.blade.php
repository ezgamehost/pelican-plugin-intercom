@php($payload = \EzGameHostLlc\Intercom\Services\IntercomBootPayload::forCurrentUser())
@if($payload)
@php($jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)
@php($widgetUrl = rtrim((string) config('intercom.api_base', 'https://widget.intercom.io'), '/') . '/widget/' . $payload['app_id'])
{{-- Identity payload is built server-side in IntercomBootPayload::forCurrentUser(). --}}
{{-- Any future changes to the field set MUST update the whitelist test. See spec §6. --}}
{{-- JSON_HEX_* flags neutralize <, >, &, ', " so user data cannot escape the <script> context. --}}
<script>
  window.intercomSettings = {!! json_encode($payload, $jsonFlags) !!};
</script>
<script>
  (function(){var w=window;var ic=w.Intercom;if(typeof ic==="function"){ic('reattach_activator');ic('update',w.intercomSettings);}else{var d=document;var i=function(){i.c(arguments);};i.q=[];i.c=function(args){i.q.push(args);};w.Intercom=i;var l=function(){var s=d.createElement('script');s.type='text/javascript';s.async=true;s.src={!! json_encode($widgetUrl, $jsonFlags) !!};var x=d.getElementsByTagName('script')[0];x.parentNode.insertBefore(s,x);};if(document.readyState==='complete'){l();}else if(w.attachEvent){w.attachEvent('onload',l);}else{w.addEventListener('load',l,false);}})();
</script>
@endif
