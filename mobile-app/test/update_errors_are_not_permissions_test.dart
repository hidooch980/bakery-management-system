import "package:bakery_app/providers/theme_provider.dart";
import "package:provider/provider.dart";
import 'package:bakery_app/screens/shared/update_screen.dart';
import 'package:bakery_app/services/update_service.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Service extends UpdateService {
  _Service({this.checkFails = false, this.installPermission = false});
  final bool checkFails;
  final bool installPermission;
  @override
  Future<String> currentVersion() async => '5.32.0';
  @override
  Future<AppUpdate?> checkForUpdate() async {
    if (checkFails) {
      lastCheckError = 'سرور در دسترس نیست';
      return null;
    }
    return const AppUpdate(
      version: '5.33.0',
      downloadUrl: 'https://server.test/a.apk',
      sizeBytes: 100,
    );
  }

  @override
  Future<void> downloadAndInstall(
    AppUpdate update, {
    void Function(double)? onProgress,
    CancelToken? cancelToken,
  }) async {
    if (installPermission) {
      throw const UpdateInstallException(
        'اجازه نصب لازم است',
        permissionDenied: true,
      );
    }
    throw DioException.connectionError(
      requestOptions: RequestOptions(path: '/a.apk'),
      reason: 'قطع اتصال',
    );
  }
}

Widget _testApp({required Widget home}) => ChangeNotifierProvider<ThemeProvider>(create: (_) => ThemeProvider(), child: MaterialApp(home: home));

void main() {
  testWidgets('خطای بررسی به شکل به‌روز بودن نمایش داده نمی‌شود', (
    tester,
  ) async {
    await tester.pumpWidget(
      _testApp(home: UpdateScreen(service: _Service(checkFails: true))),
    );
    await tester.pumpAndSettle();
    expect(find.text('بررسی نسخه انجام نشد'), findsOneWidget);
    expect(find.text('برنامه به‌روز است'), findsNothing);
    expect(find.text('سرور در دسترس نیست'), findsOneWidget);
  });

  testWidgets('خطای دانلود کاربر را به مجوز نصب نمی‌فرستد', (tester) async {
    await tester.pumpWidget(
      _testApp(home: UpdateScreen(service: _Service())),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('دانلود و نصب'));
    await tester.tap(find.text('دانلود و نصب'));
    await tester.pumpAndSettle();
    expect(find.textContaining('دانلود کامل نشد'), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);
  });

  testWidgets('فقط خطای واقعی مجوز، راهنمای نصب ناشناس نشان می‌دهد', (
    tester,
  ) async {
    await tester.pumpWidget(
      _testApp(
        home: UpdateScreen(service: _Service(installPermission: true)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('دانلود و نصب'));
    await tester.tap(find.text('دانلود و نصب'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsOneWidget);
    expect(find.text('اجازه نصب لازم است'), findsOneWidget);
  });
}
