# `urbahan-debug.apk` — device compatibility review

Inspected from the attached file only: the wrapper's source is not in this repository, so everything
below is read out of the binary and none of it has been changed. Nothing was rebuilt or re-signed.

## What the file actually is

| Property | Value |
|---|---|
| Package / version | `com.urbahan.tenant`, versionName `0.1.0`, versionCode `1` |
| Size | 17 KB, 7 entries — a thin WebView shell, no assets, no native libraries |
| ABIs | none — pure Java bytecode, so **every** device architecture is supported |
| minSdk | **24 (Android 7.0)** — excludes Android 5.x and 6.x |
| compileSdk / targetSdk | 35 / **35 (Android 15)** |
| Permissions | `android.permission.INTERNET` only |
| Manifest flags | `usesCleartextTraffic="false"`, `allowBackup="false"` |
| Activity | one: `.MainActivity`, `exported`, `configChanges` = orientation\|screenSize\|keyboardHidden, `windowSoftInputMode="adjustResize"` |
| Signing | debug certificate (`META-INF/ANDROIDD.RSA`) |
| WebView setup seen in the dex | `setJavaScriptEnabled`, `setDomStorageEnabled`, `setMixedContentMode`, `setWebViewClient`, `loadUrl` |

## Findings, worst first

### 1. Blocker — the base URL is a placeholder

`classes.dex` contains exactly one URL: `https://example.com/tenant-system/public/`. On every device the
app will open example.com, not your installation. No other fix matters until this string points at the
real host.

### 2. Blocker for LAN or HTTP hosting — cleartext is switched off

`targetSdk 35` with `usesCleartextTraffic="false"` means Android 9+ refuses any `http://` request:
the WebView shows `net::ERR_CLEARTEXT_NOT_PERMITTED`, which looks like a blank page. If the portal is
reached at `http://192.168.x.x/tenant-system/` (XAMPP, as this project runs locally), the app cannot
load it at all.

Pick one:

- **Serve the portal over HTTPS** (recommended, and required for anything tenant-facing).
- **Allow one host only**, in `res/xml/network_security_config.xml`:

  ```xml
  <network-security-config>
      <base-config cleartextTrafficPermitted="false" />
      <domain-config cleartextTrafficPermitted="true">
          <domain includeSubdomains="false">192.168.1.20</domain>
      </domain-config>
  </network-security-config>
  ```

  and reference it: `android:networkSecurityConfig="@xml/network_security_config"`.
- **Internal test builds only:** `android:usesCleartextTraffic="true"`.

### 3. High — file uploads from the portal will not work

The dex has no `WebChromeClient` or `onShowFileChooser`. The tenant portal's maintenance request form
takes a photo or PDF attachment; tapping that input does nothing without a full-screen file chooser
implementation. Tenant maintenance submission is otherwise a feature I rebuilt and verified, so this
wrapper currently blocks it.

```java
webView.setWebChromeClient(new WebChromeClient() {
    @Override
    public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback,
                                     FileChooserParams params) {
        filePathCallback = callback;
        startActivityForResult(params.createIntent(), FILE_CHOOSER_REQUEST);
        return true;
    }
});
// in onActivityResult, resolve data.getData() into Uri[] and callback.onReceiveValue(...)
```

### 4. High — CSV exports do nothing

No `DownloadListener` is present (the dex only carries a `downloadUrl` string). `report_export.php`
responds with a file download, which a bare WebView silently ignores. Landlords would tap "Export" and
see nothing happen.

```java
webView.setDownloadListener((url, ua, disposition, mime, len) ->
    startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))));
```

or intercept `shouldOverrideUrlLoading` and hand the request to the system browser.

### 5. High — debug signing

The APK is signed with a debug key. That means: Play rejects it; a later release-signed build cannot
install over it (signature mismatch) without an uninstall, which wipes the app's WebView data; and any
device with strict Play Protect policies may warn. Release needs a keystore and `apksigner`:

```
keytool -genkeypair -keystore urbahan-release.jks -alias urbahan -keyalg RSA -keysize 2048 -validity 10000
apksigner sign --ks urbahan-release.jks --out urbahan-release.apk urbahan-unsigned.apk
```

### 6. Medium — Android 15 edge-to-edge

`targetSdk 35` forces edge-to-edge on Android 15. `adjustResize` handles the keyboard but not the
status and navigation bars, so the portal's top bar and bottom content will sit under the system bars
unless the activity applies insets:

```java
ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
    Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars());
    v.setPadding(bars.left, bars.top, bars.right, bars.bottom);
    return insets;
});
```

Until that is in, `targetSdk 34` avoids the behaviour change.

### 7. Medium — the hardware back button exits the app

Nothing in the dex suggests `OnBackPressedCallback` or `canGoBack` handling. On most devices back
closes the app from any portal page instead of stepping back through history.

### 8. Low — Android 5.x and 6.x are excluded

`minSdk 24` drops Android 5.0–6.0 (roughly 2% of active devices). Dropping to 21 is safe for a plain
WebView app if those users matter.

### 9. Low — session persistence across process death

No `CookieManager.flush()` call is visible. Normal navigation keeps the session, but if Android kills
the process the tenant is signed out. Flushing cookies in `onPause` keeps sessions longer.

### 10. Note — WebView depends on the system component

On API 24–27 devices, if "Android System WebView" is disabled or very old, the app cannot render. That
is outside the APK's control but worth knowing when a single device misbehaves.

## What I can and cannot do here

I cannot patch this APK: the URL lives in compiled bytecode, editing it invalidates the signature, and
no Android SDK, Gradle or keystore is available in this workspace. Rebuilding is the correct route.

I can add a small `android/` wrapper project to this repository — Gradle config, manifest with the
network-security config, `MainActivity` with the file chooser, download handling, insets and back
navigation, and a `BuildConfig` field for the base URL — so the app is built from source instead of a
patched binary. Say the word and I will scaffold it.

## Order I would fix these in

1. Point the base URL at the real host (otherwise nothing renders).
2. Decide HTTPS vs LAN cleartext, then set the network security config to match.
3. File chooser, then download listener — both are portal features that silently fail today.
4. Release keystore and a signed build.
5. Insets for Android 15, back navigation, `minSdk` decision.
