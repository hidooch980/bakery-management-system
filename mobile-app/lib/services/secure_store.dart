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
/// **The options here must match [ApiClient]'s, and this is not a detail.**
///
/// v4.68.0 shipped this class configured with
/// `encryptedSharedPreferences: true` while ApiClient's token storage used
/// the plain default. ApiClient builds the queue and the cache as field
/// initialisers, so from that release three FlutterSecureStorage instances
/// with two different Android configurations came into existence at the
/// moment the client was constructed — the same moment the token is read.
/// Signing in succeeded, the server issued a token, and every request
/// after it came back 401 because the token could not be read back. The
/// shop could not use the app.
///
/// It had worked until then only by accident of ordering: BiometricService
/// has always set that flag, but it is built when somebody uses a
/// fingerprint, which is after the token has already been read.
///
/// So this takes the plain default — the one the token has always used —
/// rather than the stronger-looking one. The queue and the cache are still
/// off the plain preference file, which was the point; the token's path is
/// byte for byte what it was in v4.67.0.
class SecureStore {
  SecureStore({FlutterSecureStorage? storage})
      : _storage = storage ?? const FlutterSecureStorage();

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
