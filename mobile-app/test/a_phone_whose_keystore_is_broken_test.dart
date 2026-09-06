import 'dart:io';

import 'package:bakery_app/services/local_database.dart';
import 'package:bakery_app/services/response_cache.dart';
import 'package:bakery_app/services/secure_store.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:path/path.dart' as p;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// A handset whose secure storage does not work, which is a real handset.
///
/// `SecureStore.write` swallows its failures, deliberately: failing to
/// cache is better carried on from than thrown out of. But the database
/// key goes through it too, and there the swallow may be fatal:
///
///   1. read the key — the store is broken, so «absent»
///   2. mint a new one
///   3. write it — fails, silently
///   4. open the file with a key that was never saved
///
/// Every restart would mint a different key, so the file written
/// yesterday cannot be opened today. That is a suspect for the airplane
/// mode failure that has survived five releases, not a proven cause.
///
/// **Read what this test does not do.** It passes, and it passes for the
/// wrong reason. Every test in this project injects an unencrypted
/// database factory, because the SQLCipher plugin does not exist on the
/// Dart VM — so `_password` is never called by any test, and the sequence
/// above cannot be reproduced here at all. What this asserts is only that
/// a broken store does not stop the cache surviving a restart *on the
/// unencrypted path*.
///
/// The encrypted path — the one every handset actually runs — has no
/// coverage in this suite and cannot be given any without a device or an
/// emulator. That gap is the most likely reason this class of bug has
/// outlived four fixes: everything underneath was proven, and the one
/// line that decides whether the file opens at all was never executed.
///
/// Confirming or clearing the suspect needs the handset: whether the
/// «حافظهٔ داخلی گوشی کار نمی‌کند» card appears on 4.94.
class _BrokenStorage implements FlutterSecureStorage {
  @override
  Future<String?> read({required String key, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic webOptions, dynamic mOptions, dynamic wOptions}) async =>
      throw PlatformExceptionLike();

  @override
  Future<void> write({required String key, required String? value, dynamic iOptions, dynamic aOptions, dynamic lOptions, dynamic webOptions, dynamic mOptions, dynamic wOptions}) async =>
      throw PlatformExceptionLike();

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

class PlatformExceptionLike implements Exception {}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late Directory dir;
  late String path;

  setUp(() {
    FlutterSecureStorage.setMockInitialValues({});
    LocalDatabase.lastError = null;
    dir = Directory.systemTemp.createTempSync('bakery-keystore');
    path = p.join(dir.path, 'bakery_local.db');
  });

  tearDown(() => dir.deleteSync(recursive: true));

  LocalDatabase open() => LocalDatabase(
        store: SecureStore(storage: _BrokenStorage()),
        factory: databaseFactoryFfiNoIsolate,
        path: path,
      );

  test('what was saved is still there after the app is closed and reopened',
      () async {
    final first = ResponseCache(database: open());
    await first.save('/sales/my-account', null, {'data': 'ok'});

    // Closing the app and opening it again is a new LocalDatabase reading
    // the same file — which is where a key that was never saved bites.
    final second = ResponseCache(database: open());
    final held = await second.read('/sales/my-account', null, allowStale: true);

    expect(
      held?.body,
      {'data': 'ok'},
      reason: 'a phone that forgets its cache on every restart has no '
          'offline mode, however well every layer above it works',
    );
  });
}
