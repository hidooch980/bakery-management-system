package com.bakery.bakery_app

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

// اکتیویتی فرگمنت برای احراز هویت بیومتریک حفظ می‌شود.
class MainActivity : FlutterFragmentActivity() {
    private var smsPermissionResult: MethodChannel.Result? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "bakery/install_permission")
            .setMethodCallHandler { call, result ->
                if (call.method == "canInstallPackages") {
                    try {
                        result.success(Build.VERSION.SDK_INT < Build.VERSION_CODES.O ||
                            packageManager.canRequestPackageInstalls())
                    } catch (error: SecurityException) {
                        result.error("permission_check_failed", "بررسی اجازه نصب ممکن نشد.", null)
                    }
                } else {
                    result.notImplemented()
                }
            }

        // «پیامک‌های بانکی»: the switch, the permission, and the queue the
        // receiver fills while the app is closed.
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "bakery/bank_sms")
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "isEnabled" -> result.success(BankSmsStore.isEnabled(this))
                    "setEnabled" -> {
                        BankSmsStore.setEnabled(this, call.argument<Boolean>("on") == true)
                        result.success(null)
                    }
                    "setSenders" -> {
                        BankSmsStore.setSenders(this, call.argument<List<String>>("senders") ?: emptyList())
                        result.success(null)
                    }
                    "hasPermission" -> result.success(hasSmsPermission())
                    "requestPermission" -> {
                        if (hasSmsPermission()) {
                            result.success(true)
                        } else {
                            smsPermissionResult?.success(false)
                            smsPermissionResult = result
                            ActivityCompat.requestPermissions(
                                this, arrayOf(Manifest.permission.RECEIVE_SMS), SMS_REQUEST)
                        }
                    }
                    "peek" -> result.success(BankSmsStore.peek(this))
                    "remove" -> {
                        BankSmsStore.remove(this, call.argument<List<String>>("ids") ?: emptyList())
                        result.success(null)
                    }
                    else -> result.notImplemented()
                }
            }
    }

    private fun hasSmsPermission() = ContextCompat.checkSelfPermission(
        this, Manifest.permission.RECEIVE_SMS) == PackageManager.PERMISSION_GRANTED

    override fun onRequestPermissionsResult(
        requestCode: Int, permissions: Array<out String>, grantResults: IntArray,
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == SMS_REQUEST) {
            smsPermissionResult?.success(
                grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED)
            smsPermissionResult = null
        }
    }

    companion object {
        private const val SMS_REQUEST = 4207
    }
}
