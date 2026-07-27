import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release signing details live outside the repo: android/key.properties locally,
// or environment variables in CI. Every release must be signed with the SAME key,
// otherwise Android refuses to install the update over an existing install.
val keystorePropertiesFile = rootProject.file("key.properties")
val keystoreProperties = Properties().apply {
    if (keystorePropertiesFile.exists()) {
        load(keystorePropertiesFile.inputStream())
    }
}

fun signingValue(propertyKey: String, envKey: String): String? =
    keystoreProperties.getProperty(propertyKey) ?: System.getenv(envKey)

val storeFilePath = signingValue("storeFile", "ANDROID_KEYSTORE_PATH")
val hasReleaseSigning = storeFilePath != null && file(storeFilePath).exists()

android {
    namespace = "com.bakery.bakery_app"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.bakery.bakery_app"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        // 24 (Android 7.0). This was 23 to reach older staff phones, but
        // shared_preferences and local_auth both require 24 and Gradle
        // takes the highest — so the built APK already needed 24 while the
        // file claimed 23, which made install failures hard to explain.
        //
        // Lowering it again means pinning those two plugins to older
        // releases; Flutter itself refuses to build below 23 either way.
        minSdk = 24
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("release") {
                storeFile = file(storeFilePath!!)
                storePassword = signingValue("storePassword", "ANDROID_KEYSTORE_PASSWORD")
                keyAlias = signingValue("keyAlias", "ANDROID_KEY_ALIAS")
                keyPassword = signingValue("keyPassword", "ANDROID_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            // Falls back to the debug key for local `flutter run --release`;
            // CI always supplies the real keystore.
            signingConfig = if (hasReleaseSigning) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }

            isMinifyEnabled = false
            isShrinkResources = false
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

dependencies {
    // MainActivity extends FlutterFragmentActivity (required by local_auth's
    // biometric prompt), which is an AppCompatActivity and crashes at launch
    // without an AppCompat theme on the classpath.
    implementation("androidx.appcompat:appcompat:1.7.0")
}

flutter {
    source = "../.."
}
