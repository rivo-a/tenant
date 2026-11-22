# The wrapper has no reflection beyond AndroidX and the WebView, but keep the
# JavaScript-facing surface intact if an interface is ever added.
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
