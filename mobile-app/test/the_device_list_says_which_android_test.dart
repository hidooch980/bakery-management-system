import 'package:bakery_app/models/signed_in_device.dart';
import 'package:flutter_test/flutter_test.dart';

/// هر گوشی روی کدام اندروید است، در فهرست دستگاه‌ها.
///
/// فروشنده گفت APK تازه نصب نمی‌شود؛ فهرست مدل گوشی و نسخه‌ای سه
/// انتشار عقب‌تر را نام می‌برد، و هیچ‌کدام آن چیزی را نمی‌گفت که
/// اهمیت داشت — گوشی روی اندرویدی قدیمی‌تر از آنی بود که اپ از هفتمین
/// تغییرش به بعد لازم دارد.
void main() {
  SignedInDevice device({
    String? os,
    int? sdk,
    bool? canInstall,
  }) {
    return SignedInDevice.fromJson({
      'id': 1,
      'name': 'Samsung SM-J250F',
      'is_current': false,
      'app_version': '5.19.0',
      'os_version': os,
      'sdk_int': sdk,
      'can_install_updates': canInstall,
    });
  }

  test('اندروید خوانده و نشان داده می‌شود', () {
    final row = device(os: 'Android 6.0', sdk: 23, canInstall: false);

    expect(row.osVersion, 'Android 6.0');
    expect(row.sdkInt, 23);
    expect(row.osLabel, 'Android 6.0');
  });

  test('گوشیِ جامانده می‌گوید چرا، و می‌گوید اپ هنوز کار می‌کند', () {
    final row = device(os: 'Android 6.0', sdk: 23, canInstall: false);

    expect(row.strandedReason, isNotNull);
    expect(row.strandedReason, contains('Android 6.0'));

    // گوشی روی بیلدی که دارد به کار خودش ادامه می‌دهد، و دقیقاً همین
    // است که مسئله را نامرئی می‌کند — پس ردیف همین را می‌گوید.
    expect(row.strandedReason, contains('کار می‌کند'));
  });

  test('گوشی‌ای که نسخه را می‌گیرد، هشدار نمی‌گیرد', () {
    expect(
      device(os: 'Android 7.0', sdk: 24, canInstall: true).strandedReason,
      isNull,
    );
  });

  /// هشدار روی گوشی‌ای که کسی از آن خبر ندارد، آدم را می‌فرستد گوشیِ
  /// کاملاً سالم عوض کند.
  test('گوشیِ گزارش‌نداده «جا مانده» خوانده نمی‌شود', () {
    final row = device();

    expect(row.osLabel, 'اندروید نامشخص');
    expect(row.strandedReason, isNull);
  });

  /// حکم مالِ سرور است نه اپ، تا پنل و گوشی سر اینکه کدام دستگاه‌ها
  /// جا مانده‌اند اختلاف پیدا نکنند.
  test('حکم از سرور گرفته می‌شود، نه اینکه دوباره حساب شود', () {
    // سطحی زیر کف، ولی سرور می‌گوید مشکلی نیست. اپ نباید رویش حرف
    // بزند — کف در یک جا زندگی می‌کند.
    final row = device(os: 'Android 6.0', sdk: 23, canInstall: true);

    expect(row.strandedReason, isNull);
  });
}
