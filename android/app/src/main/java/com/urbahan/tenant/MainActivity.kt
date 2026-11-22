package com.urbahan.tenant

import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.os.Environment
import android.view.View
import android.view.ViewGroup.LayoutParams.MATCH_PARENT
import android.webkit.CookieManager
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.webkit.ValueCallback
import android.widget.Button
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.webkit.WebSettingsCompat
import androidx.webkit.WebViewFeature

/**
 * WebView host for the tenant portal.
 *
 * Everything the previous APK got wrong lives here: the URL comes from
 * BuildConfig (a build input, not a placeholder string), HTTPS is enforced by
 * the release network-security config, file inputs open a real chooser, CSV
 * exports reach the DownloadManager, the back button walks history, and the
 * page is inset away from the system bars on Android 15.
 */
class MainActivity : ComponentActivity() {

    private lateinit var webView: WebView
    private lateinit var errorPanel: LinearLayout
    private var fileCallback: ValueCallback<Array<Uri>>? = null

    private val startUrl: String get() = BuildConfig.BASE_URL
    private val allowedHost: String get() = Uri.parse(startUrl).host.orEmpty()

    private val fileChooser =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
            val callback = fileCallback
            fileCallback = null
            callback?.onReceiveValue(
                WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data)
            )
        }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window, false)

        val root = FrameLayout(this)
        webView = WebView(this)
        root.addView(webView, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))

        errorPanel = buildErrorPanel()
        root.addView(
            errorPanel,
            FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT)
        )
        setContentView(root)

        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val bars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.ime()
            )
            view.setPadding(bars.left, bars.top, bars.right, bars.bottom)
            insets
        }

        with(webView.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            databaseEnabled = true
            allowFileAccess = false
            allowContentAccess = false
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            mediaPlaybackRequiresUserGesture = true
            cacheMode = WebSettings.LOAD_DEFAULT
            userAgentString = "$userAgentString UrbahanApp/${BuildConfig.VERSION_NAME}"
        }

        if (WebViewFeature.isFeatureSupported(WebViewFeature.SAFE_BROWSING_ENABLE)) {
            WebSettingsCompat.setSafeBrowsingEnabled(webView.settings, true)
        }

        webView.setBackgroundColor(0xFFF8FAFC.toInt())
        webView.webViewClient = buildClient()
        webView.webChromeClient = buildChromeClient()
        webView.setDownloadListener { url, _, _, mimeType, _ -> enqueueDownload(url, mimeType) }

        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(webView, true)
        }

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (webView.canGoBack()) webView.goBack() else finish()
            }
        })

        if (BuildConfig.DEBUG) {
            WebView.setWebContentsDebuggingEnabled(true)
        }

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            webView.loadUrl(startUrl)
        }
    }

    private fun buildClient() = object : WebViewClient() {
        override fun shouldOverrideUrlLoading(
            view: WebView,
            request: WebResourceRequest
        ): Boolean {
            val uri = request.url
            val scheme = uri.scheme?.lowercase().orEmpty()

            if (scheme == "http" || scheme == "https") {
                if (uri.host.orEmpty().equals(allowedHost, ignoreCase = true)) {
                    errorPanel.visibility = View.GONE
                    return false
                }
                return openExternally(uri)
            }

            // wa.me, tel:, mailto:, intent: — always hand to the system.
            return openExternally(uri)
        }

        override fun onReceivedError(
            view: WebView,
            request: WebResourceRequest,
            error: WebResourceError
        ) {
            if (request.isForMainFrame) {
                showError(error.description?.toString().orEmpty())
            }
        }

        override fun onPageFinished(view: WebView, url: String?) {
            errorPanel.visibility = View.GONE
        }
    }

    private fun buildChromeClient() = object : WebChromeClient() {
        override fun onShowFileChooser(
            view: WebView,
            callback: ValueCallback<Array<Uri>>,
            params: FileChooserParams
        ): Boolean {
            fileCallback?.onReceiveValue(null)
            fileCallback = callback

            return try {
                fileChooser.launch(params.createIntent())
                true
            } catch (e: ActivityNotFoundException) {
                fileCallback = null
                Toast.makeText(this@MainActivity, R.string.download_failed, Toast.LENGTH_SHORT)
                    .show()
                false
            }
        }
    }

    private fun enqueueDownload(url: String, mimeType: String?) {
        try {
            val fileName = Uri.parse(url).lastPathSegment?.takeIf { it.isNotBlank() }
                ?: "urbahan-${System.currentTimeMillis()}.bin"

            val request = DownloadManager.Request(Uri.parse(url))
                .setTitle(fileName)
                .setMimeType(mimeType)
                .setNotificationVisibility(
                    DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED
                )
                .setDestinationInExternalFilesDir(
                    this,
                    Environment.DIRECTORY_DOWNLOADS,
                    fileName
                )
                .addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url).orEmpty())

            val manager = getSystemService(DOWNLOAD_SERVICE) as DownloadManager
            manager.enqueue(request)
            Toast.makeText(this, R.string.download_started, Toast.LENGTH_SHORT).show()
        } catch (e: Exception) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_LONG).show()
        }
    }

    private fun openExternally(uri: Uri): Boolean {
        return try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
            true
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show()
            true
        }
    }

    private fun buildErrorPanel(): LinearLayout {
        val panel = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = android.view.Gravity.CENTER
            setPadding(48, 48, 48, 48)
            setBackgroundColor(0xFFF8FAFC.toInt())
            visibility = View.GONE
        }

        panel.addView(
            TextView(this).apply {
                text = getString(R.string.offline_title)
                textSize = 18f
                setTextColor(0xFF0F172A.toInt())
            }
        )
        panel.addView(
            TextView(this).apply {
                text = getString(R.string.offline_body)
                textSize = 14f
                setTextColor(0xFF475569.toInt())
                setPadding(0, 16, 0, 32)
            }
        )
        panel.addView(
            Button(this).apply {
                text = getString(R.string.retry)
                setOnClickListener {
                    errorPanel.visibility = View.GONE
                    webView.reload()
                }
            }
        )

        return panel
    }

    private fun showError(description: String) {
        if (description.contains("net::ERR_CLEARTEXT_NOT_PERMITTED", ignoreCase = true)) {
            // Worth saying plainly: this build is not allowed to use plain HTTP.
            (errorPanel.getChildAt(1) as? TextView)?.text =
                "This app requires HTTPS. Point baseUrl at an https:// address."
        }
        errorPanel.visibility = View.VISIBLE
    }

    override fun onSaveInstanceState(outState: Bundle) {
        webView.saveState(outState)
        super.onSaveInstanceState(outState)
    }

    override fun onPause() {
        CookieManager.getInstance().flush()
        webView.onPause()
        super.onPause()
    }

    override fun onResume() {
        super.onResume()
        webView.onResume()
    }

    override fun onDestroy() {
        (webView.parent as? android.view.ViewGroup)?.removeView(webView)
        webView.destroy()
        super.onDestroy()
    }
}
