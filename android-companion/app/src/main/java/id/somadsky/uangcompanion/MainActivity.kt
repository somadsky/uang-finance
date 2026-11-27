package id.somadsky.uangcompanion

import android.app.Activity
import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import android.provider.Settings
import android.view.ViewGroup
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.core.app.NotificationManagerCompat
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class MainActivity : Activity() {
    private lateinit var apiUrlInput: EditText
    private lateinit var tokenInput: EditText
    private lateinit var statusText: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(20), dp(26), dp(20), dp(24))
            setBackgroundColor(Color.rgb(10, 18, 32))
        }
        val scroll = ScrollView(this).apply { addView(root) }

        root.addView(text("UANG COMPANION", 25, true, Color.WHITE))
        root.addView(text("Sinkronisasi notifikasi transaksi SeaBank", 15, false, Color.LTGRAY))
        root.addView(space(18))
        root.addView(text("URL API UANG", 14, true, Color.WHITE))
        apiUrlInput = EditText(this).apply {
            hint = "https://domain.com/uang/api.php"
            setTextColor(Color.WHITE)
            setHintTextColor(Color.GRAY)
            setSingleLine(true)
            inputType = android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_VARIATION_URI
        }
        root.addView(apiUrlInput, matchWrap())
        root.addView(space(12))

        root.addView(text("Token perangkat", 14, true, Color.WHITE))
        tokenInput = EditText(this).apply {
            hint = "Tempel token dari halaman companion.php"
            setTextColor(Color.WHITE)
            setHintTextColor(Color.GRAY)
            setSingleLine(true)
            inputType = android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD
        }
        root.addView(tokenInput, matchWrap())
        root.addView(space(8))

        val saveButton = button("Simpan konfigurasi")
        saveButton.setOnClickListener {
            try {
                SecureConfig.save(this, apiUrlInput.text.toString(), tokenInput.text.toString())
                tokenInput.setText("")
                statusText.text = "Konfigurasi tersimpan aman di Android Keystore."
            } catch (e: IllegalArgumentException) {
                statusText.text = e.message ?: "Konfigurasi tidak valid."
            } catch (_: Exception) {
                statusText.text = "Konfigurasi gagal disimpan."
            }
        }
        root.addView(saveButton, matchWrap())
        root.addView(space(10))

        val permissionButton = button("Aktifkan akses notifikasi")
        permissionButton.setOnClickListener {
            startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))
        }
        root.addView(permissionButton, matchWrap())
        root.addView(space(10))

        val testButton = button("Uji koneksi ke UANG")
        testButton.setOnClickListener { testConnection() }
        root.addView(testButton, matchWrap())
        root.addView(space(14))

        statusText = text("Status: belum diuji.", 14, false, Color.rgb(190, 205, 224))
        root.addView(statusText)
        root.addView(space(18))
        root.addView(text("Privasi", 17, true, Color.WHITE))
        root.addView(text(
            "Companion hanya memproses notifikasi dari SeaBank. Isi notifikasi tidak disimpan sebagai arsip dan tidak dikirim utuh; hanya nominal, arah transaksi yang terdeteksi jelas, tanggal, dan ID deduplikasi yang diteruskan ke UANG. Notifikasi ambigu diabaikan.",
            13, false, Color.LTGRAY
        ))
        root.addView(space(14))
        root.addView(text(
            "Jangan masukkan PIN, password, atau OTP SeaBank. Aplikasi ini tidak meminta kredensial perbankan.",
            13, true, Color.rgb(255, 210, 120)
        ))

        setContentView(scroll)
        SecureConfig.load(this)?.let {
            apiUrlInput.setText(it.apiUrl)
            statusText.text = "Konfigurasi ditemukan. Token tersimpan terenkripsi."
        }
        refreshNotificationStatus()
    }

    override fun onResume() {
        super.onResume()
        if (::statusText.isInitialized) refreshNotificationStatus()
    }

    private fun refreshNotificationStatus() {
        val enabled = NotificationManagerCompat.getEnabledListenerPackages(this).contains(packageName)
        val prefix = if (enabled) "Akses notifikasi aktif." else "Akses notifikasi belum diaktifkan."
        val old = statusText.text.toString().lineSequence()
            .filterNot { it.startsWith("Akses notifikasi ") }
            .joinToString("\n")
            .ifBlank { "Status: belum diuji." }
        statusText.text = "$prefix\n$old"
    }

    private fun testConnection() {
        val config = SecureConfig.load(this)
        if (config == null) {
            statusText.text = "Simpan URL API dan token perangkat terlebih dahulu."
            return
        }
        statusText.text = "Menguji koneksi…"
        Thread {
            var connection: HttpURLConnection? = null
            val result = try {
                val endpoint = config.apiUrl.trimEnd('/') + "?action=companion.ping"
                connection = (URL(endpoint).openConnection() as HttpURLConnection).apply {
                    requestMethod = "GET"
                    connectTimeout = 12_000
                    readTimeout = 12_000
                    setRequestProperty("Authorization", "Bearer ${config.token}")
                    setRequestProperty("X-Requested-With", "uang-companion")
                    setRequestProperty("Accept", "application/json")
                }
                val status = connection.responseCode
                val stream = if (status in 200..299) connection.inputStream else connection.errorStream
                val body = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
                val json = JSONObject(body)
                if (status in 200..299 && json.optBoolean("ok")) {
                    "Koneksi berhasil. Perangkat terdaftar: " +
                        json.optJSONObject("data")?.optString("device", "Android")
                } else {
                    "Koneksi ditolak (HTTP $status): " + json.optString("error", "Periksa token dan URL.")
                }
            } catch (_: Exception) {
                "Tidak dapat terhubung. Periksa HTTPS, URL API, jaringan, dan token."
            } finally {
                connection?.disconnect()
            }
            runOnUiThread { statusText.text = result }
        }.start()
    }

    private fun text(value: String, size: Int, bold: Boolean, color: Int): TextView =
        TextView(this).apply {
            text = value
            textSize = size.toFloat()
            setTextColor(color)
            if (bold) setTypeface(typeface, android.graphics.Typeface.BOLD)
        }

    private fun button(label: String): Button = Button(this).apply {
        text = label
        isAllCaps = false
        setTextColor(Color.WHITE)
    }

    private fun space(height: Int): TextView = TextView(this).apply {
        layoutParams = ViewGroup.LayoutParams(1, dp(height))
    }

    private fun matchWrap() = LinearLayout.LayoutParams(
        ViewGroup.LayoutParams.MATCH_PARENT,
        ViewGroup.LayoutParams.WRAP_CONTENT
    )

    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}
