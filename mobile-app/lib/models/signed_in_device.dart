/// One handset holding a session, as the device list shows it.
class SignedInDevice {
  const SignedInDevice({
    required this.id,
    required this.name,
    required this.isCurrent,
    this.lastUsedAt,
    this.createdAt,
    this.appVersion,
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
}
