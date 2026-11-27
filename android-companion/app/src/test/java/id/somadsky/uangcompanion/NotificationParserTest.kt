package id.somadsky.uangcompanion

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class NotificationParserTest {
    @Test
    fun parsesExplicitExpenseNotification() {
        val result = NotificationParser.parse(
            notificationKey = "test-key",
            postTimeMillis = 1764000000000,
            title = "Pembayaran QRIS berhasil",
            text = "Transaksi sebesar Rp25.000 berhasil."
        )
        assertEquals(25_000L, result?.amount)
        assertEquals("expense", result?.direction)
    }

    @Test
    fun parsesExplicitIncomingTransfer() {
        val result = NotificationParser.parse(
            notificationKey = "test-key",
            postTimeMillis = 1764000000000,
            title = "Dana masuk",
            text = "Dana masuk sebesar Rp1.250.000"
        )
        assertEquals(1_250_000L, result?.amount)
        assertEquals("income", result?.direction)
    }

    @Test
    fun ignoresAmbiguousNotification() {
        val result = NotificationParser.parse(
            notificationKey = "test-key",
            postTimeMillis = 1764000000000,
            title = "Informasi SeaBank",
            text = "Nikmati kemudahan transaksi digital."
        )
        assertNull(result)
    }
}
