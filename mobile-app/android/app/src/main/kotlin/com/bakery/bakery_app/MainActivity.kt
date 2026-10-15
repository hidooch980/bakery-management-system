package com.bakery.bakery_app

import android.os.Build
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

// اکتیویتی فرگمنت برای احراز هویت بیومتریک حفظ می‌شود.
class MainActivity : FlutterFragmentActivity() {
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
    }
}
