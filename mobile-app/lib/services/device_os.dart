import 'dart:io';

import 'package:device_info_plus/device_info_plus.dart';

/// این گوشی روی کدام اندروید — یا iOS — کار می‌کند.
///
/// فهرست دستگاه‌ها مدتی است نام گوشی و بیلد را می‌گوید، و معلوم شد آن
/// دو سوم از سؤال است که جواب نمی‌دهد. فروشنده گفت APK تازه نصب
/// نمی‌شود؛ فهرست می‌گفت «Samsung SM-J250F» و نسخه‌ای سه انتشار
/// عقب‌تر، و هیچ‌کدام آن چیزی را نمی‌گفت که اهمیت داشت — گوشی روی
/// اندرویدی قدیمی‌تر از آنی بود که اپ از هفتمین تغییرش به بعد لازم
/// دارد، پس هیچ نسخه‌ای از آن موقع تا حالا نمی‌توانسته رویش نصب شود،
/// و هیچ‌کس نمی‌توانست این را از هیچ صفحه‌ای بفهمد.
///
/// دو مقدار، نه یکی. [name] چیزی است که آدم می‌شناسد («Android 7.0») و
/// [sdkInt] عددی است که بیلد واقعاً با آن مقایسه می‌شود. درآوردنِ یکی
/// از روی آن یکی، هر جا که لازم شود، همان راهی است که این دو به اختلاف
/// می‌رسند.
///
/// یک بار خوانده و نگه داشته می‌شود، و *بیرون* از مسیر درخواست — به
/// همان دلیلی که [AppVersion] این‌طور است: کانالِ پلتفرم جلوی هر
/// درخواست، همان کانالی است که زیر `flutter test` هرگز جواب نمی‌دهد، و
/// هدری که فقط یک راحتی است هرگز نباید جلوی فروش نان را بگیرد.
class DeviceOs {
  static String? _name;

  static int? _sdkInt;

  /// چیزی که در هدر می‌رود، بدون منتظرماندن برای هیچ چیز.
  static String? get cachedName => _name;

  /// سطحِ API، یا خالی روی iOS و هر جا که گزارش نشده باشد.
  static int? get cachedSdkInt => _sdkInt;

  /// یک بار، موقع راه‌اندازی. هرگز خطا نمی‌اندازد و هرگز گیر نمی‌کند.
  static Future<void> warmUp() async {
    if (_name != null) return;

    try {
      final info = DeviceInfoPlugin();

      if (Platform.isAndroid) {
        final android = await info.androidInfo
            .timeout(const Duration(seconds: 3));

        _sdkInt = android.version.sdkInt;
        _name = _tidy('Android ${android.version.release}');

        return;
      }

      if (Platform.isIOS) {
        final ios = await info.iosInfo.timeout(const Duration(seconds: 3));

        _name = _tidy('iOS ${ios.systemVersion}');
      }
    } on Object {
      // گوشی‌ای که نتواند نسخه‌اش را بگوید، هنوز نان می‌فروشد.
    }
  }

  /// در سی کاراکتری که سرور ذخیره می‌کند نگهش می‌دارد، و هر چه را
  /// سرور رد می‌کند می‌تراشد — تا رشتهٔ عجیبِ یک سازنده کوتاه‌شده
  /// برسد، نه اینکه یکسره دور ریخته شود.
  static String? _tidy(String raw) {
    final value = raw
        .replaceAll(RegExp(r'[^0-9A-Za-z. -]'), '')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();

    if (value.isEmpty) return null;

    return value.length <= 30 ? value : value.substring(0, 30);
  }

  /// درزِ آزمون. کانالِ پلتفرم زیر `flutter test` در دسترس نیست، پس
  /// آزمونی که به مقادیر معلوم نیاز دارد خودش می‌گذاردشان.
  static void setForTesting({String? name, int? sdkInt}) {
    _name = name;
    _sdkInt = sdkInt;
  }
}
