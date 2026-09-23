/// One handset holding a session, as the device list shows it.
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

  /// The phone this list is being read on.
  ///
  /// Carried rather than worked out here: the app cannot tell which of
  /// several sessions is its own from the rows alone, and guessing wrong
  /// means offering to close the wrong one.
  final bool isCurrent;

  /// Already Jalali, formatted by the server. Null when the session has
  /// been opened but nothing has been asked of it yet.
  final String? lastUsedAt;
  final String? createdAt;

  /// Which build is on that handset, once it has made a request carrying
  /// the header. Null for a session opened by an app old enough not to
  /// send one — and that is itself the answer: it has not been updated.
  final String? appVersion;

  /// Which Android the handset is on — «Android 7.0».
  ///
  /// The missing third of the question. A seller reporting that the new
  /// APK will not install is nearly always holding a phone too old to
  /// take it, and until this was recorded no screen could say so.
  final String? osVersion;

  /// The API level, which is what the build is actually compared
  /// against. Kept beside the name rather than parsed back out of it.
  final int? sdkInt;

  /// Whether a release can be installed on this handset at all.
  ///
  /// Decided by the server, not here, so the panel and the app cannot
  /// disagree about which phones are stranded. Null when the level was
  /// never reported.
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

  /// What to put under the name.
  ///
  /// «هرگز» would be wrong for a session opened a minute ago and not yet
  /// used, and it is the row somebody is most likely to be looking at.
  String get when {
    if (lastUsedAt != null) return 'آخرین استفاده: $lastUsedAt';
    if (createdAt != null) return 'ورود: $createdAt';

    return 'بدون سابقهٔ استفاده';
  }

  /// The build, said plainly, or that nobody knows.
  ///
  /// «نامشخص» rather than nothing: a blank where a version belongs reads
  /// as a bug in the list, where the actual fact — this phone has not
  /// spoken to the server since it was updated to a build that reports —
  /// is worth seeing.
  String get versionLabel =>
      appVersion == null ? 'نسخه نامشخص' : 'نسخهٔ $appVersion';

  /// The Android, said plainly, or that nobody knows.
  String get osLabel => osVersion ?? 'اندروید نامشخص';

  /// Why this handset can never take an update, when it cannot.
  ///
  /// Null when it can, and null when nobody knows — a warning shown on a
  /// phone that is merely unreported would send somebody to replace a
  /// handset that is perfectly fine.
  ///
  /// Worth saying out loud on the row rather than leaving somebody to
  /// compare two numbers: the phone goes on working on the build it
  /// already has, which is exactly why the problem is invisible.
  String? get strandedReason {
    if (canInstallUpdates != false) return null;

    final on = osVersion ?? 'این اندروید';

    return 'نسخهٔ تازه روی $on نصب نمی‌شود — اپ فعلی کار می‌کند،'
        ' ولی به‌روزرسانی نمی‌گیرد.';
  }
}
