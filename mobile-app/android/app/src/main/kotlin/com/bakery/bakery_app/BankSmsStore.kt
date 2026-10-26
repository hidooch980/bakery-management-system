package com.bakery.bakery_app

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

/** The phone-side queue of bank SMS waiting to reach the server. */
object BankSmsStore {
    private const val PREFS = "bank_sms"
    private const val KEY_ENABLED = "enabled"
    private const val KEY_QUEUE = "queue"
    private const val KEY_SENDERS = "senders"
    private const val MAX = 500

    private val otp = Regex("(رمز|کد ?(تایید|تأیید|فعال)|پویا|OTP)", RegexOption.IGNORE_CASE)
    private val money = Regex("(واریز|واريز|برداشت|مبلغ|انتقال|خرید|خريد)")
    // Sepah often names no bank («واریز:… / حساب / مانده / 8/4-4:51»): a
    // balance or account line is as good a sign.
    private val bankWord = Regex("(بانک|بانك|مانده|حساب)")

    private fun prefs(c: Context) = c.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun isEnabled(c: Context) = prefs(c).getBoolean(KEY_ENABLED, false)

    fun setEnabled(c: Context, on: Boolean) {
        prefs(c).edit().putBoolean(KEY_ENABLED, on).apply()
        if (!on) prefs(c).edit().remove(KEY_QUEUE).apply()
    }

    fun setSenders(c: Context, senders: List<String>) {
        prefs(c).edit().putString(KEY_SENDERS, JSONArray(senders).toString()).apply()
    }

    private fun senders(c: Context): List<String> {
        val raw = prefs(c).getString(KEY_SENDERS, null) ?: return emptyList()
        val arr = JSONArray(raw)
        return (0 until arr.length()).map { arr.getString(it) }
    }

    private fun digits(s: String) = s.filter { it.isDigit() }.trimStart('0').removePrefix("98")

    fun looksLikeBank(c: Context, sender: String, body: String): Boolean {
        if (otp.containsMatchIn(body)) return false
        if (!money.containsMatchIn(body)) return false
        val known = senders(c).any { it.isNotBlank() && digits(it) == digits(sender) }
        return known || bankWord.containsMatchIn(body)
    }

    @Synchronized
    fun add(c: Context, sender: String, body: String, at: Long) {
        val queue = JSONArray(prefs(c).getString(KEY_QUEUE, "[]"))
        val item = JSONObject().put("id", "$at-${body.hashCode()}")
            .put("sender", sender).put("body", body).put("at", at)
        queue.put(item)
        while (queue.length() > MAX) queue.remove(0)
        prefs(c).edit().putString(KEY_QUEUE, queue.toString()).apply()
    }

    @Synchronized
    fun peek(c: Context): List<Map<String, Any>> {
        val queue = JSONArray(prefs(c).getString(KEY_QUEUE, "[]"))
        return (0 until queue.length()).map {
            val o = queue.getJSONObject(it)
            mapOf("id" to o.getString("id"), "sender" to o.getString("sender"),
                "body" to o.getString("body"), "at" to o.getLong("at"))
        }
    }

    /** Drops what the server confirmed it has. */
    @Synchronized
    fun remove(c: Context, ids: List<String>) {
        val queue = JSONArray(prefs(c).getString(KEY_QUEUE, "[]"))
        val keep = JSONArray()
        for (i in 0 until queue.length()) {
            val o = queue.getJSONObject(i)
            if (o.getString("id") !in ids) keep.put(o)
        }
        prefs(c).edit().putString(KEY_QUEUE, keep.toString()).apply()
    }
}
