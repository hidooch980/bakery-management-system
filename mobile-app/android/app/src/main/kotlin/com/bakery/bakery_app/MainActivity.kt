package com.bakery.bakery_app

import io.flutter.embedding.android.FlutterFragmentActivity

// FlutterFragmentActivity, not FlutterActivity: local_auth presents the
// biometric prompt as a fragment and fails with "no_fragment_activity"
// against a plain FlutterActivity.
class MainActivity : FlutterFragmentActivity()
