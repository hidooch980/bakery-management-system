/// One handset holding a session, as the device list shows it.
class SignedInDevice {
  const SignedInDevice({
    required this.id,
    required this.name,
    required this.isCurrent,
    this.lastUsedAt,
    this.createdAt,
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

  factory SignedInDevice.fromJson(Map<String, dynamic> json) {
    return SignedInDevice(
      id: (json['id'] as num).toInt(),
      name: (json['name'] as String?)?.trim().isNotEmpty == true
          ? json['name'] as String
          : 'دستگاه ناشناس',
      isCurrent: json['is_current'] == true,
      lastUsedAt: json['last_used_at'] as String?,
      createdAt: json['created_at'] as String?,
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
}
