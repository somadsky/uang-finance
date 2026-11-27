package id.somadsky.uangcompanion

import android.app.Notification
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf

class SeaBankNotificationListener : NotificationListenerService() {
    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (sbn.packageName != SEABANK_PACKAGE) return
        val extras = sbn.notification.extras ?: return
        val title = extras.getCharSequence(Notification.EXTRA_TITLE)?.toString().orEmpty()
        val textParts = mutableListOf<String>()
        extras.getCharSequence(Notification.EXTRA_TEXT)?.toString()
            ?.takeIf { it.isNotBlank() }?.let(textParts::add)
        extras.getCharSequence(Notification.EXTRA_BIG_TEXT)?.toString()
            ?.takeIf { it.isNotBlank() }?.let(textParts::add)
        extras.getCharSequenceArray(Notification.EXTRA_TEXT_LINES)
            ?.map { it.toString() }?.filter { it.isNotBlank() }?.let(textParts::addAll)

        val parsed = NotificationParser.parse(sbn.key, sbn.postTime, title, textParts.distinct().joinToString(" "))
            ?: return

        val request = OneTimeWorkRequestBuilder<SyncWorker>()
            .setInputData(workDataOf(
                SyncWorker.KEY_EVENT_ID to parsed.eventId,
                SyncWorker.KEY_AMOUNT to parsed.amount,
                SyncWorker.KEY_DIRECTION to parsed.direction,
                SyncWorker.KEY_DATE to parsed.date,
                SyncWorker.KEY_DESCRIPTION to parsed.description
            ))
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()

        WorkManager.getInstance(applicationContext).enqueueUniqueWork(
            "seabank-${parsed.eventId}",
            ExistingWorkPolicy.KEEP,
            request
        )
    }

    companion object {
        const val SEABANK_PACKAGE = "id.co.bankbkemobile.digitalbank"
    }
}
