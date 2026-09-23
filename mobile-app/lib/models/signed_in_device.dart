/// یک گوشی که نشستی روی آن باز است، همان‌طور که فهرست دستگاه‌ها
/// نشانش می‌دهد.
class SignedInDevice {
  const SignedInDevice({
    required this.id,
    required this.name,
    required this.isCurrent,
    this.lastUsedAt,
    this.createdAt,
    this.appVersion,
    this.osVersion,
    this.sdkInt,
    this.canInstallUpdates,
  });

  final int id;
  final String name;

  /// گوشی‌ای که این فهرست روی آن خوانده می‌شود.
  ///
  /// آورده می‌شود نه اینجا حساب: اپ از روی خودِ ردیف‌ها نمی‌تواند
  /// بفهمد کدام نشست مالِ خودش است، و حدسِ غلط یعنی پیشنهادِ بستنِ
  /// نشستِ اشتباه.
  final bool isCurrent;

  /// از قبل شمسی، به دست سرور قالب‌بندی شده. وقتی نشست باز شده ولی
  /// هنوز چیزی از آن خواسته نشده، خالی است.
  final String? lastUsedAt;
  final String? createdAt;

  /// کدام بیلد روی آن گوشی است، بعد از اینکه یک درخواست با آن هدر
  /// فرستاده باشد. برای نشستی که اپِ قدیمی‌تر بازش کرده خالی است — و
  /// همین خودش جواب است: آن گوشی به‌روز نشده.
  final String? appVersion;

  /// گوشی روی کدام اندروید است — «Android 7.0».
  ///
  /// آن یک‌سومِ گمشدهٔ سؤال. فروشنده‌ای که می‌گوید APK تازه نصب
  /// نمی‌شود، تقریباً همیشه گوشی‌ای در دست دارد که زیادی قدیمی است، و
  /// تا وقتی این ثبت نمی‌شد هیچ صفحه‌ای نمی‌توانست بگوید.
  final String? osVersion;

  /// سطحِ API، که بیلد واقعاً با آن مقایسه می‌شود. کنار نام نگه داشته
  /// می‌شود نه اینکه دوباره از رویش درآورده شود.
  final int? sdkInt;

  /// اینکه اصلاً یک نسخه روی این گوشی نصب می‌شود یا نه.
  ///
  /// سرور تصمیم می‌گیرد، نه اینجا، تا پنل و اپ سر اینکه کدام گوشی‌ها
  /// جا مانده‌اند اختلاف پیدا نکنند. اگر سطح هرگز گزارش نشده باشد،
  /// خالی است.
  final bool? canInstallUpdates;

  factory SignedInDevice.fromJson(Map<String, dynamic> json) {
    return SignedInDevice(
      id: (json['id'] as num).toInt(),
      name: (json['name'] as String?)?.trim().isNotEmpty == true
          ? json['name'] as String
          : 'دستگاه ناشناس',
      isCurrent: json['is_current'] == true,
      lastUsedAt: json['last_used_at'] as String?,
      createdAt: json['created_at'] as String?,
      appVersion: (json['app_version'] as String?)?.trim().isEmpty == true
          ? null
          : json['app_version'] as String?,
      osVersion: (json['os_version'] as String?)?.trim().isEmpty == true
          ? null
          : json['os_version'] as String?,
      sdkInt: (json['sdk_int'] as num?)?.toInt(),
      canInstallUpdates: json['can_install_updates'] as bool?,
    );
  }

  /// چیزی که زیر نام نوشته می‌شود.
  ///
  /// «هرگز» برای نشستی که یک دقیقه پیش باز شده و هنوز استفاده نشده غلط
  /// است، و همان ردیفی است که آدم بیشتر از همه نگاهش می‌کند.
  String get when {
    if (lastUsedAt != null) return 'آخرین استفاده: $lastUsedAt';
    if (createdAt != null) return 'ورود: $createdAt';

    return 'بدون سابقهٔ استفاده';
  }

  /// بیلد، ساده گفته‌شده، یا اینکه کسی نمی‌داند.
  ///
  /// «نامشخص» به‌جای هیچ: جای خالی آنجا که نسخه باید باشد، مثل یک
  /// ایرادِ فهرست خوانده می‌شود، در حالی که واقعیتش — این گوشی از وقتی
  /// به بیلدی که گزارش می‌دهد به‌روز شده با سرور حرف نزده — دیدنی
  /// است.
  String get versionLabel =>
      appVersion == null ? 'نسخه نامشخص' : 'نسخهٔ $appVersion';

  /// اندروید، ساده گفته‌شده، یا اینکه کسی نمی‌داند.
  String get osLabel => osVersion ?? 'اندروید نامشخص';

  /// چرا این گوشی هرگز به‌روزرسانی نمی‌گیرد، وقتی نمی‌گیرد.
  ///
  /// وقتی می‌گیرد خالی است، و وقتی کسی نمی‌داند هم خالی است — هشدار
  /// روی گوشی‌ای که فقط گزارش نداده، کسی را می‌فرستد گوشیِ کاملاً سالم
  /// عوض کند.
  ///
  /// روی خودِ ردیف صریح گفته می‌شود، نه اینکه کسی دو عدد را با هم
  /// مقایسه کند: گوشی روی بیلدی که دارد به کار خودش ادامه می‌دهد، و
  /// دقیقاً همین است که مسئله را نامرئی می‌کند.
  String? get strandedReason {
    if (canInstallUpdates != false) return null;

    final on = osVersion ?? 'این اندروید';

    return 'نسخهٔ تازه روی $on نصب نمی‌شود — اپ فعلی کار می‌کند،'
        ' ولی به‌روزرسانی نمی‌گیرد.';
  }
}
