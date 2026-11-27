package id.somadsky.uangcompanion

import java.security.MessageDigest
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

data class ParsedSeaBankTransaction(
    val eventId: String,
    val amount: Long,
    val direction: String,
    val date: String,
    val description: String
)

object NotificationParser {
    private val rupiahPattern = Regex("(?i)\\bRp\\.?\\s*([0-9][0-9.,]*)")
    private val datePattern = Regex("\\b([0-3]?[0-9])[/-]([01]?[0-9])[/-]([0-9]{4})\\b")
    private val incomePattern = Regex(
        "(?i)\\b(dana masuk|uang masuk|refund berhasil|pengembalian dana|cashback masuk|bunga tabungan)\\b"
    )
    private val expensePattern = Regex(
        "(?i)\\b(pembayaran berhasil|berhasil membayar|pembelian berhasil|pembayaran|pembelian|transaksi debit|qris)\\b"
    )
    private val transferPattern = Regex("(?i)\\b(transfer|top ?up|tarik tunai|penarikan)\\b")

    fun parse(notificationKey: String, postTimeMillis: Long, title: String, text: String): ParsedSeaBankTransaction? {
        val combined = listOf(title, text).filter { it.isNotBlank() }.joinToString(" ")
        if (combined.isBlank() || transferPattern.containsMatchIn(combined)) return null

        val incoming = incomePattern.containsMatchIn(combined)
        val outgoing = expensePattern.containsMatchIn(combined)
        val direction = when {
            incoming && !outgoing -> "income"
            outgoing && !incoming -> "expense"
            else -> return null
        }

        val amountMatch = rupiahPattern.find(combined) ?: return null
        val amountDigits = amountMatch.groupValues[1].replace(Regex("[^0-9]"), "")
        val amount = amountDigits.toLongOrNull() ?: return null
        if (amount <= 0L || amount > 1_000_000_000_000L) return null

        val date = parseDate(combined, postTimeMillis) ?: return null
        val eventId = sha256("$notificationKey|$postTimeMillis|$combined")
        val description = if (direction == "income") "Dana masuk SeaBank" else "Transaksi keluar SeaBank"

        return ParsedSeaBankTransaction(eventId, amount, direction, date, description)
    }

    private fun parseDate(text: String, fallbackMillis: Long): String? {
        val match = datePattern.find(text)
        if (match != null) {
            val day = match.groupValues[1].toIntOrNull() ?: return null
            val month = match.groupValues[2].toIntOrNull() ?: return null
            val year = match.groupValues[3].toIntOrNull() ?: return null
            return try {
                LocalDate.of(year, month, day).toString()
            } catch (_: Exception) {
                null
            }
        }
        return Instant.ofEpochMilli(fallbackMillis)
            .atZone(ZoneId.of("Asia/Jakarta"))
            .toLocalDate()
            .toString()
    }

    private fun sha256(value: String): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(value.toByteArray(Charsets.UTF_8))
        return digest.joinToString("") { "%02x".format(it) }
    }
}
