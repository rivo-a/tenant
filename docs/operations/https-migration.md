# Moving the tenant system to HTTPS

Two tracks: a LAN certificate for development, a public certificate for release. The code change is the
same for both and is already in place.

## Code that makes the switch

| File | What it does |
|---|---|
| `config/config.php` | `APP_URL` comes from `APP_BASE_URL`; `BASE_URL` stays the `/tenant-system/public` path so existing links are untouched |
| `core/https.php` | `is_https_request()`, `require_https()` (single 301 to the canonical URL, path and query preserved), `send_hsts_header()` |
| `core/bootstrap.php` | calls `require_https()` and HSTS before output; session cookie is `Secure` whenever the app is configured for HTTPS; CSP adds `upgrade-insecure-requests` over HTTPS |

Set in `.env`:

```
APP_BASE_URL=https://<host>/tenant-system/public
HSTS_ENABLED=false          # true only once the certificate is proven
TRUSTED_PROXIES=            # optional, comma separated, for X-Forwarded-Proto
```

While `APP_BASE_URL` starts with `http://`, the redirect does nothing — so development is not disturbed.

## Track 1 — development on the LAN (mkcert)

1. `choco install mkcert` then `mkcert -install` (creates a local CA and trusts it on this machine).
2. Generate a certificate for the hostname and the LAN address:

   ```bash
   mkcert -cert-file C:/xampp/apache/conf/ssl.crt/urbahan.crt \
          -key-file  C:/xampp/apache/conf/ssl.crt/urbahan.key \
          urbahan.local 192.168.1.17 localhost
   ```

3. First find out who owns port 443. It was already bound (PID 18876) before this work; if that is not
   Apache, either stop it or move Apache's SSL listener to 8443 and use
   `https://urbahan.local:8443/tenant-system/`.
4. `conf/extra/httpd-ssl.conf`: point `SSLCertificateFile` and `SSLCertificateKeyFile` at the files from
   step 2, set `ServerName urbahan.local:443`, and make sure the `tenant-system` directory is served.
5. Add a port-80 vhost that redirects to `https://urbahan.local/tenant-system/`.
6. Map the name: `192.168.1.17  urbahan.local` in `C:/Windows/System32/drivers/etc/hosts`.
7. Restart Apache, then set `APP_BASE_URL=https://urbahan.local/tenant-system/public`.

**Android caveat:** phones trust user certificates for browsing, but apps ignore them since Android 7.
The debug build therefore trusts user CAs explicitly (`app/src/debug/res/xml/network_security_config.xml`),
so install the mkcert CA on the test device (Settings → Security → Encryption & credentials → Install a
certificate → CA certificate). Release builds trust only public CAs — by design.

## Track 2 — production (Cloudflare Tunnel recommended)

Port 443 is occupied locally, and a tunnel needs no port forwarding, no certificate renewal and gives a
public CA that Android trusts out of the box.

1. Create a `tenants.<domain>` DNS record in Cloudflare.
2. Install `cloudflared`, then:

   ```bash
   cloudflared tunnel login
   cloudflared tunnel create urbahan
   cloudflared tunnel route dns urbahan tenants.<domain>
   cloudflared tunnel run --url http://localhost:80 urbahan     # Apache stays as it is
   cloudflared service install                                  # run as a Windows service
   ```

3. Cloudflare terminates TLS and sets `X-Forwarded-Proto: https`, which `core/bootstrap.php` already
   honours, so the session cookie becomes `Secure`.
4. Set `APP_BASE_URL=https://tenants.<domain>/tenant-system/public`.
5. After confirming the login flow twice on two devices, set `HSTS_ENABLED=true`.

Alternative without Cloudflare: `win-acme` issues a Let's Encrypt certificate for Apache and renews it on
a scheduled task, but Apache must own port 443.

## Verification

- `http://<host>/tenant-system/public/payments.php` → exactly one 301 to the `https://` equivalent with
  path and query intact.
- Devtools: session cookie `Secure; HttpOnly; SameSite=Strict`; login survives a reload.
- Network panel: no `http://` requests (fonts, icons, uploads).
- Portal: login, maintenance photo upload, receipt download, CSV export.
- App: loads, uploads a photo, downloads the export.
- `Strict-Transport-Security` present only after `HSTS_ENABLED=true`.

## Rollback

Set `APP_BASE_URL` back to the `http://` address and Apache keeps serving as before; `require_https()`
does nothing while the configured scheme is http. No database change is involved.
