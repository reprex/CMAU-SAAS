/* Passkeys (Face ID) for CMAU SaaS sign-in. WebAuthn args come from the server with binary values as base64url. */
(function () {
  var b64u = {
    toBuf: function (s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '='; var b = atob(s), a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a.buffer; },
    fromBuf: function (buf) { var a = new Uint8Array(buf), s = ''; for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  };
  function decode(pk) {
    pk.challenge = b64u.toBuf(pk.challenge);
    if (pk.user && pk.user.id) pk.user.id = b64u.toBuf(pk.user.id);
    (pk.allowCredentials || []).forEach(function (c) { c.id = b64u.toBuf(c.id); });
    (pk.excludeCredentials || []).forEach(function (c) { c.id = b64u.toBuf(c.id); });
    return pk;
  }
  function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
  function post(url, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || j.message || 'Something went wrong'); return j; }); });
  }
  function get(url) { return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { if (!r.ok) throw new Error('Something went wrong'); return r.json(); }); }
  function say(el, msg) { if (el) { el.textContent = msg; el.hidden = !msg; } }

  window.CMPasskey = {
    supported: function () { return !!(window.PublicKeyCredential && navigator.credentials); },
    signIn: function (optionsUrl, loginUrl, msgEl) {
      say(msgEl, '');
      return get(optionsUrl).then(function (args) { return navigator.credentials.get({ publicKey: decode(args.publicKey) }); })
        .then(function (cred) {
          return post(loginUrl, { id: cred.id, clientDataJSON: b64u.fromBuf(cred.response.clientDataJSON),
            authenticatorData: b64u.fromBuf(cred.response.authenticatorData), signature: b64u.fromBuf(cred.response.signature) });
        })
        .then(function (r) { window.location = r.redirect; })
        .catch(function (e) { say(msgEl, e.name === 'NotAllowedError' ? 'Cancelled. Try again, or sign in with your password.' : e.message); });
    },
    register: function (optionsUrl, storeUrl, msgEl) {
      say(msgEl, '');
      return get(optionsUrl).then(function (args) { return navigator.credentials.create({ publicKey: decode(args.publicKey) }); })
        .then(function (cred) { return post(storeUrl, { clientDataJSON: b64u.fromBuf(cred.response.clientDataJSON), attestationObject: b64u.fromBuf(cred.response.attestationObject) }); })
        .then(function () { window.location.reload(); })
        .catch(function (e) { say(msgEl, e.name === 'NotAllowedError' ? 'Cancelled.' : e.message); });
    }
  };
})();
/* Buttons: data-passkey="signin|register" data-options="url" data-url="url" data-msg="#id". Shown only where it works. */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-passkey]').forEach(function (btn) {
    var wrap = btn.closest('[data-passkey-wrap]') || btn;
    if (!window.CMPasskey.supported()) { wrap.hidden = true; return; }
    wrap.hidden = false;
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var msg = document.querySelector(btn.getAttribute('data-msg'));
      var fn = btn.getAttribute('data-passkey') === 'register' ? window.CMPasskey.register : window.CMPasskey.signIn;
      fn(btn.getAttribute('data-options'), btn.getAttribute('data-url'), msg);
    });
  });
});
