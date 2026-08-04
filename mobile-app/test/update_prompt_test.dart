import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'package:bakery_app/services/update_service.dart';
import 'package:bakery_app/widgets/update_prompt.dart';

/// Serves one canned GitHub Releases payload, so the prompt can be tested
/// without the network.
class _FakeAdapter implements HttpClientAdapter {
  _FakeAdapter(this.body);

  final String body;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    return ResponseBody.fromString(
      body,
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

UpdateService _serviceOffering(String tag) {
  final dio = Dio();
  dio.httpClientAdapter = _FakeAdapter(
    '{"tag_name":"$tag","body":"","assets":'
    '[{"name":"bakery-app.apk","browser_download_url":"https://x.test/a.apk","size":1024}]}',
  );

  return UpdateService(dio: dio, repo: 'owner/name');
}

Future<void> _pumpPrompt(WidgetTester tester, UpdateService service) async {
  UpdatePrompt.resetForTest();

  await tester.pumpWidget(MaterialApp(
    home: UpdatePrompt(
      service: service,
      child: const Scaffold(body: Text('صفحه اصلی')),
    ),
  ));

  await tester.pumpAndSettle();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    // The check reads the installed version before comparing.
    PackageInfo.setMockInitialValues(
      appName: 'bakery',
      packageName: 'com.bakery.bakery_app',
      version: '1.0.0',
      buildNumber: '1',
      buildSignature: '',
    );
  });

  testWidgets('offers the update when a newer build is published',
      (tester) async {
    await _pumpPrompt(tester, _serviceOffering('v2.0.0'));

    expect(find.text('بروزرسانی لازم است'), findsOneWidget);
    // Both versions are named, so the user can see what they have against
    // what is waiting.
    expect(find.text('1.0.0'), findsOneWidget);
    expect(find.text('2.0.0'), findsOneWidget);
  });

  testWidgets('says nothing at all when already up to date', (tester) async {
    await _pumpPrompt(tester, _serviceOffering('v1.0.0'));

    expect(find.text('بروزرسانی لازم است'), findsNothing);
    // The user's own screen is never covered by the check.
    expect(find.text('صفحه اصلی'), findsOneWidget);
  });

  testWidgets('waving it off leaves a standing warning, not silence',
      (tester) async {
    await _pumpPrompt(tester, _serviceOffering('v2.0.0'));

    await tester.tap(find.text('بعداً'));
    await tester.pumpAndSettle();

    // The dialog goes, but the warning stays: waving it off does not make
    // the old build any more able to reach the server.
    expect(find.text('بروزرسانی لازم است'), findsNothing);
    expect(find.textContaining('بروزرسانی نکرده‌اید'), findsOneWidget);
    expect(find.text('صفحه اصلی'), findsOneWidget);
  });

  testWidgets('does not ask twice in one session', (tester) async {
    final service = _serviceOffering('v2.0.0');

    await _pumpPrompt(tester, service);
    await tester.tap(find.text('بعداً'));
    await tester.pumpAndSettle();

    // Rebuilding — switching screens, say — must not ask again.
    await tester.pumpWidget(MaterialApp(
      home: UpdatePrompt(
        service: service,
        child: const Scaffold(body: Text('صفحه دیگر')),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('بروزرسانی لازم است'), findsNothing);
  });

  testWidgets('a failed check is silent, never an error on screen',
      (tester) async {
    final dio = Dio();
    dio.httpClientAdapter = _FakeAdapter('not json at all');

    await _pumpPrompt(tester, UpdateService(dio: dio, repo: 'owner/name'));

    expect(find.text('بروزرسانی لازم است'), findsNothing);
    expect(find.textContaining('بروزرسانی نکرده‌اید'), findsNothing);
    expect(find.text('صفحه اصلی'), findsOneWidget);
  });
}
