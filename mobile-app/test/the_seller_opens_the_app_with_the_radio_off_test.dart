import 'dart:io';
import 'dart:typed_data';

import 'package:bakery_app/providers/auth_provider.dart';
import 'package:bakery_app/providers/theme_provider.dart';
import 'package:bakery_app/screens/seller/seller_home_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/services/connection_status.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The seller's own screen, with the radio off, as a screen.
///
/// Four releases have fixed real bugs underneath this and the owner has
/// said «کار نکرد» after every one. Every test that passed was a test of a
/// layer: the queue holds a sale, the cache serves a copy, the API falls
/// back. None of them mounted the thing he actually looks at, so a screen
/// that throws while its services are all working perfectly is invisible
/// here and total on the handset.
///
/// This mounts it. The wire answers while the phone has signal, then stops
/// answering, and the question is only ever what is on the screen.
class _Wire implements HttpClientAdapter {
  _Wire(this.bodies);

  final Map<String, String> bodies;

  bool down = false;

  /// Every path the screen asked for, in order. A path the screen needs
  /// and this map does not have is the finding, not a broken test.
  final asked = <String>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    asked.add(options.path);

    if (down) {
      throw const SocketException('No address associated with hostname');
    }

    final body = bodies[options.path];

    if (body == null) {
      return ResponseBody.fromString('{"success":true,"data":[]}', 200,
          headers: {
            Headers.contentTypeHeader: [Headers.jsonContentType],
          });
    }

    return ResponseBody.fromString(body, 200, headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    });
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Wire wire;
  late BakeryApi api;
  late ApiClient client;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    wire = _Wire({
      '/chane-entries/pending': '{"success":true,"data":[]}',
      '/sales/today':
          '{"success":true,"data":{"sales":[],"count":0,"total":0}}',
      '/bakery': '{"success":true,"data":{"id":1,"name":"نانوایی",'
          '"currency":"toman","bread_price":5000,"flour_bag_weight_kg":40}}',
    });

    client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(wire);
    api = BakeryApi(client);
  });

  Widget underTest() => MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => AuthProvider(api)),
          ChangeNotifierProvider(create: (_) => ConnectionStatus(client)),
          ChangeNotifierProvider(create: (_) => ThemeProvider()),
        ],
        child: MaterialApp(home: SellerHomeScreen(api: api)),
      );

  testWidgets('with signal it comes up, and without one it still does',
      (tester) async {
    // A handset, not the 800x600 the test binding defaults to: an
    // overflow at that size is the harness talking, not the app.
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(underTest());
    await tester.pumpAndSettle();

    wire.asked.clear();
    wire.down = true;

    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(underTest());
    await tester.pumpAndSettle();

    // What the owner sees. Anything that got here by throwing is the bug.
    expect(find.byType(SellerHomeScreen), findsOneWidget);
    expect(
      find.textContaining('خطا'),
      findsNothing,
      reason: 'the screen asked for: ${wire.asked}',
    );
  });
}
