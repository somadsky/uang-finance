package id.somadsky.uangcompanion

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

class SyncWorker(appContext: Context, workerParams: WorkerParameters) : Worker(appContext, workerParams) {
    override fun doWork(): Result {
        val config = SecureConfig.load(applicationContext) ?: return Result.failure()
        val eventId = inputData.getString(KEY_EVENT_ID) ?: return Result.failure()
        val amount = inputData.getLong(KEY_AMOUNT, 0L)
        val direction = inputData.getString(KEY_DIRECTION) ?: return Result.failure()
        val date = inputData.getString(KEY_DATE) ?: return Result.failure()
        val description = inputData.getString(KEY_DESCRIPTION) ?: "Transaksi SeaBank"

        if (amount <= 0L || (direction != "income" && direction != "expense")) return Result.failure()

        var connection: HttpURLConnection? = null
        return try {
            val endpoint = config.apiUrl.trimEnd('/') + "?action=companion.ingest"
            connection = (URL(endpoint).openConnection() as HttpURLConnection).apply {
                requestMethod = "POST"
                connectTimeout = 15_000
                readTimeout = 20_000
                doOutput = true
                setRequestProperty("Authorization", "Bearer ${config.token}")
                setRequestProperty("X-Requested-With", "uang-companion")
                setRequestProperty("Content-Type", "application/json; charset=utf-8")
                setRequestProperty("Accept", "application/json")
            }

            val payload = JSONObject()
                .put("eventId", eventId)
                .put("amount", amount)
                .put("direction", direction)
                .put("date", date)
                .put("description", description)

            connection.outputStream.use { it.write(payload.toString().toByteArray(Charsets.UTF_8)) }
            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream
            val responseText = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            val response = runCatching { JSONObject(responseText) }.getOrNull()
            val accepted = status in 200..299 && response?.optBoolean("ok", false) == true

            when {
                accepted -> Result.success()
                status >= 500 || status == 408 || status == 429 ->
                    if (runAttemptCount < 8) Result.retry() else Result.failure()
                else -> Result.failure()
            }
        } catch (_: Exception) {
            if (runAttemptCount < 8) Result.retry() else Result.failure()
        } finally {
            connection?.disconnect()
        }
    }

    companion object {
        const val KEY_EVENT_ID = "event_id"
        const val KEY_AMOUNT = "amount"
        const val KEY_DIRECTION = "direction"
        const val KEY_DATE = "date"
        const val KEY_DESCRIPTION = "description"
    }
}
