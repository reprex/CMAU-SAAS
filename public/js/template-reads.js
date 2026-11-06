/* Range › Template reads: the CrowdMerch logo drawn in each print area the way the website draws a client's logo
   (same maths as the theme's CM.quadMatrix / CM.surfaceArt, cm-app.js): laid out in the area, curved to the
   surface (cylinder or dome) on a canvas, then mapped onto the four corners with a perspective transform.
   Toggle remembered per browser. */
(function () {
  function quadMatrix(w, h, q) {
    var x0 = q[0][0], y0 = q[0][1], x1 = q[1][0], y1 = q[1][1], x2 = q[2][0], y2 = q[2][1], x3 = q[3][0], y3 = q[3][1];
    var dx1 = x1 - x2, dx2 = x3 - x2, dx3 = x0 - x1 + x2 - x3, dy1 = y1 - y2, dy2 = y3 - y2, dy3 = y0 - y1 + y2 - y3;
    var a, b, c = x0, d, e, f = y0, g = 0, hh = 0, den = dx1 * dy2 - dx2 * dy1;
    if ((Math.abs(dx3) < 1e-9 && Math.abs(dy3) < 1e-9) || Math.abs(den) < 1e-9) { a = x1 - x0; b = x3 - x0; d = y1 - y0; e = y3 - y0; }
    else { g = (dx3 * dy2 - dx2 * dy3) / den; hh = (dx1 * dy3 - dx3 * dy1) / den; a = x1 - x0 + g * x1; b = x3 - x0 + hh * x3; d = y1 - y0 + g * y1; e = y3 - y0 + hh * y3; }
    var m = [a / w, d / w, 0, g / w, b / h, e / h, 0, hh / h, 0, 0, 1, 0, c, f, 0, 1];
    return 'matrix3d(' + m.map(function (v) { return +v.toFixed(8); }).join(',') + ')';
  }
  var logo = null, cache = {};
  function art(aspect, surface) {
    var key = aspect.toFixed(2) + surface;
    if (cache[key]) return cache[key];
    var asp = Math.max(0.1, Math.min(10, aspect)), CW = asp >= 1 ? 600 : Math.round(600 * asp), CH = asp >= 1 ? Math.round(600 / asp) : 600;
    var lay = document.createElement('canvas'); lay.width = CW; lay.height = CH;
    var lx = lay.getContext('2d'), iw = logo.naturalWidth, ih = logo.naturalHeight, k = Math.min(CW * 0.92 / iw, CH * 0.92 / ih);
    lx.drawImage(logo, (CW - iw * k) / 2, (CH - ih * k) / 2, iw * k, ih * k);
    var out = document.createElement('canvas'); out.width = CW; out.height = CH; var A = 1.0;
    var cols = function (from, to) { var tx = to.getContext('2d'); for (var i = 0; i < CW; i++) { var t = (i + 0.5) / CW * 2 - 1, su = 0.5 + Math.asin(Math.max(-1, Math.min(1, t * Math.sin(A)))) / (2 * A); tx.globalAlpha = 1 - 0.3 * Math.pow(Math.abs(t), 3); tx.drawImage(from, Math.max(0, Math.min(CW - 1, su * CW)), 0, 1, CH, i, 0, 1, CH); } tx.globalAlpha = 1; };
    var rows = function (from, to) { var tx = to.getContext('2d'); for (var j = 0; j < CH; j++) { var t = (j + 0.5) / CH * 2 - 1, sv = 0.5 + Math.asin(Math.max(-1, Math.min(1, t * Math.sin(A)))) / (2 * A); tx.globalAlpha = 1 - 0.3 * Math.pow(Math.abs(t), 3); tx.drawImage(from, 0, Math.max(0, Math.min(CH - 1, sv * CH)), CW, 1, 0, j, CW, 1); } tx.globalAlpha = 1; };
    if (surface === 'cylinder_v') cols(lay, out); else if (surface === 'cylinder_h') rows(lay, out);
    else if (surface === 'dome') { var mid = document.createElement('canvas'); mid.width = CW; mid.height = CH; cols(lay, mid); rows(mid, out); }
    else out.getContext('2d').drawImage(lay, 0, 0);
    return (cache[key] = { url: out.toDataURL(), w: CW, h: CH });
  }
  function place(img) {
    var box = img.parentElement, W = box.clientWidth, H = box.clientHeight; if (!W || !H || !img._art) return;
    var px = img._q.map(function (p) { return [p[0] / 100 * W, p[1] / 100 * H]; });
    img.style.width = img._art.w + 'px'; img.style.height = img._art.h + 'px';
    img.style.transform = quadMatrix(img._art.w, img._art.h, px);
  }
  function draw() {
    document.querySelectorAll('img.tread-logo').forEach(function (img) {
      if (!img._art) {
        try { img._q = JSON.parse(img.getAttribute('data-quad')); } catch (e) { return; }
        img._art = art(+img.getAttribute('data-aspect') || 1, img.getAttribute('data-surface') || 'flat');
        img.src = img._art.url;
      }
      place(img);
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    var list = document.querySelector('.treads'), box = document.getElementById('show-logo');
    if (!list || !box) return;
    var on; try { on = localStorage.getItem('treads:logo') !== '0'; } catch (e) { on = true; }
    box.checked = on;
    var apply = function () {
      list.classList.toggle('show-logo', box.checked);
      try { localStorage.setItem('treads:logo', box.checked ? '1' : '0'); } catch (e) {}
      if (box.checked) { if (logo) draw(); else { logo = new Image(); logo.onload = draw; logo.src = list.getAttribute('data-logo'); } }
    };
    box.addEventListener('change', apply);
    window.addEventListener('resize', function () { if (box.checked && logo) document.querySelectorAll('img.tread-logo').forEach(place); });
    apply();
  });
})();
