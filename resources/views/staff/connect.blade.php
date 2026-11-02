<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Staff tools</title>
<style nonce="{{ $nonce }}">body{font-family:system-ui,sans-serif;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#F6F5F2;color:#1A1B18}.box{background:#fff;border-radius:16px;padding:28px 30px;max-width:360px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.08)}h1{font-size:20px;margin:0 0 8px}p{margin:6px 0;color:#555}</style></head>
<body><div class="box"><h1>Signed in, {{ explode(' ', $payload['name'])[0] }}</h1><p data-msg>Sending your staff pass to the website…</p></div>
<script nonce="{{ $nonce }}">
(function () {
  var payload = @json($payload), origin = @json($origin), msg = document.querySelector('[data-msg]');
  if (window.opener) {
    window.opener.postMessage(payload, origin);
    msg.textContent = 'Done. You can close this window.';
    setTimeout(function () { window.close(); }, 600);
  } else {
    msg.textContent = 'Open this from the website\'s Staff sign-in so the pass can be handed over.';
  }
})();
</script></body></html>
