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

  bool can(String permission) => permissions.contains(permission);
}
