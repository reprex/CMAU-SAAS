# Stock relay: take the Promodata token out of the CMAU theme

The PDP's **Check stock** button used to call Promodata from the visitor's browser, with the Promodata API token in the page's public JavaScript. `public/stock.php` makes that call from the server instead. The theme calls the relay, and the token never reaches the browser. The stock table looks and works the same.

## 1. Upload the relay (cPanel, no SSH)

The subdomain `saas.app.crowdmerch.com.au` must have its document root on `public/` and a working HTTPS certificate.

1. Upload these files, keeping their folders:
   - `public/stock.php`
   - `src/bootstrap.php`
   - `src/Stock/StockRelay.php`
2. Copy `config/config.example.php` to `config/config.php`, outside `public/`.
3. In `config/config.php`, fill in `promodata_stock`:
   - `token`: the **new** Promodata token (step 3 below). It is only ever typed into this file.
   - `allowed_origins`: add the CMAUDEV store address before testing there.
4. Make sure PHP can write to `var/` next to `public/`. It holds the 5-minute cache and visitor counters, never the token.

**Check:** `https://saas.app.crowdmerch.com.au/stock.php?id=43446` in a browser should return JSON starting `{"data":{"stock":[[`.

## 2. Change the theme (test on CMAUDEV first)

In **Edit code**, find the `#check_stock` click handler. Search for `check-stock-levels`.

Replace:

```javascript
"url": "https://api.promodata.com.au/products/"+productId+"/check-stock-levels",
"method": "POST",
"timeout": 0,
"headers": {
  "X-Auth-Token": "…"
},
```

with:

```javascript
"url": "https://saas.app.crowdmerch.com.au/stock.php?id="+productId,
"method": "GET",
"timeout": 20000,
```

Nothing else changes: the response is Promodata's own JSON, so the code that reads `response.data.stock` and fills the table stays as it is.

**Check:** on a product page, Check stock fills the table, and the page source no longer contains `X-Auth-Token`.

## 3. Replace the token (same day)

1. Ask Promodata for a new API token and to deactivate the old one. The old one has been public, so treat it as compromised.
2. Put the new token in `config/config.php` on the server.
3. Update anything else that used the old token, such as TOD's Promodata settings, or those syncs stop working.

## What the relay enforces

- Numeric product ids only.
- Requests only from the store origins in `allowed_origins`.
- One Promodata call per product per 5 minutes; repeat clicks are served from the cache.
- At most 30 uncached look-ups per visitor per 5 minutes. Visitor IPs are stored only as hashes.
- Promodata failures come back as an error, and the theme's existing failure handling restores the button.
