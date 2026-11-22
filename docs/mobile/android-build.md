# Building the Android app

The project lives in `android/`. It replaces the patched debug APK, which pointed at `example.com`,
blocked HTTPS-only traffic, and had no file chooser or download handling.

## Prerequisites

- JDK 17 (`winget install EclipseAdoptium.Temurin.17.JDK`) — verify with `java -version`
- Android command-line SDK in `%LOCALAPPDATA%\Android\Sdk`, with `platform-tools`,
  `platforms;android-35`, `build-tools;35.0.0`
- `android/local.properties` containing `sdk.dir=C\:\\Users\\<you>\\AppData\\Local\\Android\\Sdk`
  (git-ignored)
- Gradle comes from the wrapper; the first run downloads it

## Base URL is a build input

```bash
./gradlew :app:assembleDebug  -PbaseUrl=http://10.0.2.2/tenant-system/          # emulator
./gradlew :app:assembleRelease -PbaseUrl=https://tenants.example.com/tenant-system/
```

Default comes from `baseUrl` in `gradle.properties`. The value is compiled into `BuildConfig.BASE_URL`;
nothing is hardcoded in Kotlin. A release build refuses cleartext through the network-security config,
so pointing it at `http://` produces a clear "requires HTTPS" screen rather than a blank page.

## Signing

The keystore lives **outside** the repository — `%USERPROFILE%\.urbahan\urbahan-release.jks`:

```bash
keytool -genkeypair -v -keystore %USERPROFILE%\.urbahan\urbahan-release.jks \
  -alias urbahan -keyalg RSA -keysize 2048 -validity 10000
```

`android/keystore.properties` (git-ignored) points at it:

```
storeFile=C:/Users/<you>/.urbahan/urbahan-release.jks
storePassword=<password>
keyAlias=urbahan
keyPassword=<password>
```

Losing that file means no future update can install over the existing app. Back it up somewhere that is
not this repository.

## Build and verify

```bash
cd android
./gradlew :app:assembleRelease -PbaseUrl=https://<host>/tenant-system/
# output: app/build/outputs/apk/release/app-release.apk
$ANDROID_HOME/build-tools/35.0.0/apksigner verify --print-certs app-release.apk
$ANDROID_HOME/build-tools/35.0.0/aapt2 dump badging app-release.apk | head -5
```

`aapt2 dump badging` should show `package: name='com.urbahan.tenant'`, `minSdkVersion:'24'`,
`targetSdkVersion:'35'`.

## What differs between debug and release

| Concern | Debug | Release |
|---|---|---|
| Application id | `com.urbahan.tenant.debug` | `com.urbahan.tenant` |
| Cleartext | allowed for `10.0.2.2`, `localhost`, `192.168.1.17` | refused everywhere |
| Trust anchors | system + user CA (mkcert works) | system only |
| WebView debugging | enabled | disabled |

Both variants sit side by side on a device, so a debug build can be tested against a LAN host while the
release build stays HTTPS-only.
