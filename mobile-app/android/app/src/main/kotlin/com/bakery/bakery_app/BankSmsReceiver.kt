package com.bakery.bakery_app

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony

/**
 * «پیامک‌های بانکی»: keeps a new bank SMS until the app sends it.
 *
 * Does nothing unless the owner turned the feature on in the app. Only
 * messages that look like a bank's money message are kept — OTP codes and
 * everything personal are dropped here and never leave the phone. The
 * queue is the app's private storage; the app empties it once the server
 * has the message.
 */
class BankSmsReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return
        if (!BankSmsStore.isEnabled(context)) return

        val parts = Telephony.Sms.Intents.getMessagesFromIntent(intent) ?: return
        // One SMS can arrive in several parts; they belong together by sender.
        parts.groupBy { it.originatingAddress ?: "" }.forEach { (sender, pieces) ->
            val body = pieces.joinToString("") { it.messageBody ?: "" }
            val at = pieces.minOf { it.timestampMillis }
            if (BankSmsStore.looksLikeBank(context, sender, body)) {
                BankSmsStore.add(context, sender, body, at)
            }
        }
    }
}
