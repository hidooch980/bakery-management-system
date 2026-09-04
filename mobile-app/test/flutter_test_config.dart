import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'package:bakery_app/services/local_database.dart';

/// Runs once before every test file in this directory.
///
/// The local database is SQLCipher on a handset, and neither that plugin
/// nor sqflite's own exists on the Dart VM a test runs on. `ApiClient`
/// builds an `OfflineQueue` as a field initialiser, so any test that
/// constructs a client would otherwise have to know about databases to do
/// anything at all.
///
/// The tests get an unencrypted in-memory database. What they prove is the
/// SQL; the encryption is a property of the file on the phone rather than
/// of the statements run against it, and it has no test here because there
/// is nothing on this side of the plugin boundary to run one against.
Future<void> testExecutable(FutureOr<void> Function() testMain) async {
  sqfliteFfiInit();
  LocalDatabase.factoryForTesting = databaseFactoryFfi;

  // Every in-memory database shares one path, and sqflite keys its cache
  // of open handles on the path — which is what makes «a queue built
  // fresh still sees the entry» true here as it is on a phone. The same
  // sharing carries rows from one test into the next, so the file is
  // dropped between them. Registered here rather than in each test file:
  // a leak of this kind does not fail, it counts wrong.
  setUp(() async {
    await databaseFactoryFfi.deleteDatabase(inMemoryDatabasePath);
  });

  await testMain();
}
