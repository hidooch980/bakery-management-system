import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Where anything about the shop's money is kept on the phone.
///
/// The token and the biometric credentials were already here. Everything
/// else the app writes went to SharedPreferences, which on Android is a
/// plain XML file and on a rooted or seized handset is simply readable.
/// Two of those were not preferences at all:
///
///   - the offline queue, which holds sales that have not reached the
///     server yet — amounts, customers, notes;
///   - the read cache, which holds whole API responses, and therefore
///     wages, bank balances and what every customer owes.
///
/// A phone left in a taxi should cost the shop a phone.
///
/// `encryptedSharedPreferences` is what makes this real on Android — the
/// same flag [BiometricService] already sets, backed by Jetpack Security.
/// On iOS it is the keychain. Neither is free: this costs more per write
/// than a preference file did, and the read cache writes on every
/// successful GET. Worth watching on a real handset rather than assuming.
class SecureStore {
  SecureStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(encryptedSharedPreferences: true),
            );

  final FlutterSecureStorage _storage;

  Future<String?> read(String key) async {
    try {
      return await _storage.read(key: key);
    } on Object {
      // A store that will not open must not take a screen down with it.
      // Unreadable and absent are the same answer to every caller here.
      return null;
    }
  }

  Future<void> write(String key, String value) async {
    try {
      await _storage.write(key: key, value: value);
    } on Object {
      // Same reasoning: failing to cache, or failing to queue, is worse
      // handled by throwing than by carrying on.
    }
  }

  Future<void> delete(String key) async {
    try {
      await _storage.delete(key: key);
    } on Object {
      // ignored, as above
    }
  }

  /// Every key this store holds. Needed because the read cache and the
  /// queue both have to find their own entries without keeping a separate
  /// index that could drift from what is actually stored.
  Future<Set<String>> keys() async {
    try {
      return (await _storage.readAll()).keys.toSet();
    } on Object {
      return {};
    }
  }
}
