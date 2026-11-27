package id.somadsky.uangcompanion

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

data class CompanionConfig(val apiUrl: String, val token: String)

object SecureConfig {
    private const val PREFS = "uang_companion_config"
    private const val KEY_ALIAS = "uang_companion_token_key"
    private const val TRANSFORMATION = "AES/GCM/NoPadding"

    fun save(context: Context, apiUrlInput: String, tokenInput: String) {
        val apiUrl = apiUrlInput.trim().trimEnd('/')
        val token = tokenInput.trim()
        val uri = android.net.Uri.parse(apiUrl)
        require(uri.scheme == "https" && !uri.host.isNullOrBlank()) {
            "URL harus menggunakan HTTPS, contoh: https://domain.com/uang/api.php"
        }
        require(uri.path?.endsWith("/api.php") == true) {
            "URL harus mengarah ke file api.php UANG."
        }
        require(token.matches(Regex("^[a-fA-F0-9]{64}$"))) {
            "Token perangkat harus berisi 64 karakter heksadesimal."
        }
        val encrypted = encrypt(token)
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString("api_url", apiUrl)
            .putString("token_enc", encrypted)
            .apply()
    }

    fun load(context: Context): CompanionConfig? {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val url = prefs.getString("api_url", null) ?: return null
        val encrypted = prefs.getString("token_enc", null) ?: return null
        return try {
            CompanionConfig(url, decrypt(encrypted))
        } catch (_: Exception) {
            clear(context)
            null
        }
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
    }

    private fun key(): SecretKey {
        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val existing = keyStore.getKey(KEY_ALIAS, null) as? SecretKey
        if (existing != null) return existing
        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build()
        )
        return generator.generateKey()
    }

    private fun encrypt(plainText: String): String {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, key())
        val encrypted = cipher.doFinal(plainText.toByteArray(Charsets.UTF_8))
        return Base64.encodeToString(cipher.iv + encrypted, Base64.NO_WRAP)
    }

    private fun decrypt(encoded: String): String {
        val payload = Base64.decode(encoded, Base64.NO_WRAP)
        require(payload.size > 12) { "Token terenkripsi tidak valid." }
        val iv = payload.copyOfRange(0, 12)
        val encrypted = payload.copyOfRange(12, payload.size)
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, iv))
        return cipher.doFinal(encrypted).toString(Charsets.UTF_8)
    }
}
