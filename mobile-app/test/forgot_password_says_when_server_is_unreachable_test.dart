import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/screens/auth/forgot_password_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// گوشی بی‌اینترنت: درخواست به سرور نمی‌رسد.
class _NoSignal implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
      RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    throw DioException(
      requestOptions: options,
      type: DioExceptionType.unknown,
      error: const SocketException('Failed host lookup: server.test'),
    );
  }

  @override
  void close({bool force = false}) {}
}

/// سرور پاسخ داده: محدودیت تعداد درخواست.
class _TooMany implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
      RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    return ResponseBody.fromString(
      '{"message":"تعداد درخواست‌ها زیاد است. کمی بعد دوباره امتحان کنید."}',
      429,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  Future<void> pump(WidgetTester tester, HttpClientAdapter adapter) async {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1')..transport = adapter;
    await tester.pumpWidget(MaterialApp(
      home: ForgotPasswordScreen(api: BakeryApi(client), phone: '09159991669'),
    ));
    await tester.tap(find.text('کد را بفرست'));
    // درخواست Dio بیرون از ساعت جعلی آزمون انجام می‌شود.
    for (var i = 0; i < 150 && find.byType(SnackBar).evaluate().isEmpty; i++) {
      await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
      await tester.pump(const Duration(milliseconds: 100));
    }
  }

  testWidgets('بی‌اینترنتی پیام «اتصال به سرور برقرار نشد» نشان می‌دهد', (tester) async {
    await pump(tester, _NoSignal());
    expect(find.text('اتصال به سرور برقرار نشد'), findsOneWidget);
  });

  testWidgets('محدودیت تعداد درخواست خطای شبکه خوانده نمی‌شود', (tester) async {
    await pump(tester, _TooMany());
    expect(find.byType(SnackBar), findsOneWidget);
    expect(find.text('اتصال به سرور برقرار نشد'), findsNothing);
  });

  test('فقط خطای اتصال به پیام شبکه تبدیل می‌شود', () {
    expect(forgotPasswordError(ApiException('x', isConnectivityError: true)),
        'اتصال به سرور برقرار نشد');
    expect(
        forgotPasswordError(ApiException('شماره درست نیست', statusCode: 422)), 'شماره درست نیست');
  });
}
