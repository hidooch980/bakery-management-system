import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/services/connection_status.dart';
import 'package:bakery_app/services/offline_queue.dart';
import 'package:bakery_app/widgets/sync_status_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// A write the server refused has said what it was since the day refusals
/// stopped being deleted — «what was refused is something a person typed
/// and is entitled to see».
///
/// A write still waiting said «۳ مورد» and nothing else. So a seller on a
/// bad signal could not tell whether the sale he had just entered was one
/// of the three, and the safe-looking move is to enter it again.
///
/// `QueuedRequest.label` carries the comment «What to show in the
/// pending-sync list». That list was never built.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late ApiClient client;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
    client = ApiClient(baseUrl: 'http://server.test/api/v1');
  });

  Future<void> queue(String label, {required String id, int minute = 0}) =>
      client.queue.enqueue(QueuedRequest(
        id: id,
        path: '/sales',
        body: const {},
        label: label,
        createdAt: DateTime(2026, 9, 7, 8, minute),
      ));

  Future<void> pump(WidgetTester tester) async {
    await tester.pumpWidget(MaterialApp(
      home: ChangeNotifierProvider<ConnectionStatus>(
        create: (_) => ConnectionStatus(client),
        child: Scaffold(body: SyncStatusCard(api: BakeryApi(client))),
      ),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
  }

  testWidgets('each waiting write is named, not just counted',
      (tester) async {
    await queue('ثبت فروش — ۲۰ نان', id: 'a', minute: 10);
    await queue('ثبت خمیر — ۱۰ کیسه', id: 'b', minute: 25);

    await pump(tester);

    expect(find.textContaining('2 مورد'), findsOneWidget);
    expect(find.text('ثبت فروش — ۲۰ نان'), findsOneWidget);
    expect(find.text('ثبت خمیر — ۱۰ کیسه'), findsOneWidget);
  });

  testWidgets('the time it was entered is beside it', (tester) async {
    // Which of two similar entries is the one just made is answered by
    // when it was written down, not by its position in a list.
    await queue('ثبت فروش — ۲۰ نان', id: 'a', minute: 41);

    await pump(tester);

    expect(find.text('08:41'), findsOneWidget);
  });

  testWidgets('an empty queue draws no list at all', (tester) async {
    await pump(tester);

    expect(tester.takeException(), isNull);
    expect(find.textContaining('مورد ثبت‌شده در انتظار'), findsNothing);
  });
}
