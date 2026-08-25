/// The roles the backend can assign. Kept as an enum so screen routing
/// never depends on a raw string typo.
enum UserRole {
  admin,
  doughMaker,
  chaneGir,
  shater,
  seller,
  unknown;

  static UserRole fromApi(String? value) => switch (value) {
        'admin' => UserRole.admin,
        'dough_maker' => UserRole.doughMaker,
        'chane_gir' => UserRole.chaneGir,
        'shater' => UserRole.shater,
        'seller' => UserRole.seller,
        _ => UserRole.unknown,
      };

  /// The inverse of [fromApi]. Needed because a stored user is written
  /// back through the same JSON shape the server sends.
  String get apiValue => switch (this) {
        UserRole.admin => 'admin',
        UserRole.doughMaker => 'dough_maker',
        UserRole.chaneGir => 'chane_gir',
        UserRole.shater => 'shater',
        UserRole.seller => 'seller',
        UserRole.unknown => 'unknown',
      };

  String get label => switch (this) {
        UserRole.admin => 'مدیر',
        UserRole.doughMaker => 'خمیرگیر',
        UserRole.chaneGir => 'چانه‌گیر',
        UserRole.shater => 'شاطر',
        UserRole.seller => 'فروشنده',
        UserRole.unknown => 'نامشخص',
      };
}

class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    required this.role,
    this.email,
    this.phone,
    this.permissions = const [],
  });

  final int id;
  final String name;
  final String? email;
  final String? phone;
  final UserRole role;
  final List<String> permissions;

  factory AppUser.fromJson(Map<String, dynamic> json) {
    final roles = (json['roles'] as List?)?.cast<String>() ?? const [];

    return AppUser(
      id: json['id'] as int,
      name: json['name'] as String? ?? '',
      email: json['email'] as String?,
      phone: json['phone'] as String?,
      role: UserRole.fromApi(roles.isEmpty ? null : roles.first),
      permissions: (json['permissions'] as List?)?.cast<String>() ?? const [],
    );
  }

  /// The shape `fromJson` reads, so a stored copy can be handed straight
  /// back to it. Written for the offline cold start: without a user there
  /// is no role, and without a role the app cannot choose a screen.
  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'phone': phone,
        'roles': [role.apiValue],
        'permissions': permissions,
      };

  bool can(String permission) => permissions.contains(permission);
}
