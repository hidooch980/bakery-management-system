import 'dart:io';
import 'dart:ui' as ui;
import 'package:bakery_app/models/payroll.dart';
import 'package:bakery_app/screens/admin/admin_staff_tab.dart';
import 'package:bakery_app/screens/admin/admin_finance_tab.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:bakery_app/theme/app_theme.dart';
import 'package:bakery_app/widgets/admin_detail_group.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));
  int attendanceReads = 0;
  @override
  Future<List<Employee>> payrollEmployees() async => [
        const Employee(
            id: 1,
            name: 'علی احمدی',
            monthlySalary: 20000000,
            monthlySalaryFormatted: '۲۰٬۰۰۰٬۰۰۰ تومان'),
        const Employee(
            id: 2,
            name: 'رضا شاطر',
            monthlySalary: 18000000,
            monthlySalaryFormatted: '۱۸٬۰۰۰٬۰۰۰ تومان'),
      ];
  @override
  Future<List<Map<String, dynamic>>> adminAttendanceToday() async {
    attendanceReads++;
    throw Exception('گزارش حضور در دسترس نیست');
  }

  @override
  Future<Map<String, dynamic>> financialReport(
          {String? from, String? to}) async =>
      {
        'profit': {
          'formatted': '۱۲٬۰۰۰٬۰۰۰ تومان',
          'is_positive': true,
          'margin_percent': 25
        },
        'income': {'total_formatted': '۴۸٬۰۰۰٬۰۰۰ تومان'},
        'expenses': {'total_formatted': '۳۶٬۰۰۰٬۰۰۰ تومان'},
      };
}

Future<void> _preview(WidgetTester tester, String name) async {
  final dir = Platform.environment['BAKERY_UI_PREVIEW_DIR'];
  if (dir == null) {
    return;
  }
  await tester.runAsync(() async {
    final boundary = tester.renderObject<RenderRepaintBoundary>(
        find.byKey(const ValueKey('preview')));
    final image = await boundary.toImage(pixelRatio: 2);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    await Directory(dir).create(recursive: true);
    await File('$dir/$name.png').writeAsBytes(bytes!.buffer.asUint8List());
    image.dispose();
  });
}

void main() {
  setUpAll(() async {
    for (final weight in ['Regular', 'Medium', 'Bold']) {
      final loader = FontLoader('Vazirmatn')
        ..addFont(rootBundle.load('assets/fonts/Vazirmatn-$weight.ttf'));
      await loader.load();
    }
    final icons = FontLoader('MaterialIcons')
      ..addFont(rootBundle.load('fonts/MaterialIcons-Regular.otf'));
    await icons.load();
  });
  setUp(() => FlutterSecureStorage.setMockInitialValues({}));
  Future<void> show(WidgetTester tester, Widget page,
      {double scale = 1}) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light(),
        home: MediaQuery(
            data: MediaQueryData(
                size: const Size(360, 800),
                textScaler: TextScaler.linear(scale)),
            child: Directionality(
                textDirection: TextDirection.rtl,
                child: RepaintBoundary(
                    key: const ValueKey('preview'),
                    child: Scaffold(body: page))))));
    await tester.pumpAndSettle();
  }

  testWidgets(
      'نام کارکنان بدون انتظار برای حضور دیده می‌شود و خطای حضور فهرست را نمی‌بندد',
      (tester) async {
    final api = _Api();
    await show(tester, AdminStaffTab(api: api));
    expect(find.text('علی احمدی'), findsOneWidget);
    expect(api.attendanceReads, 0);
    await _preview(tester, 'staff');
    await tester.ensureVisible(find.text('حضور امروز'));
    await tester.tap(find.text('حضور امروز'));
    await tester.pumpAndSettle();
    expect(api.attendanceReads, 1);
    expect(find.textContaining('گزارش حضور در دسترس نیست'), findsOneWidget);
    expect(find.text('علی احمدی'), findsOneWidget);
  });
  testWidgets('فهرست مرتب کارکنان در فونت بزرگ خطای چیدمان ندارد',
      (tester) async {
    await show(tester, AdminStaffTab(api: _Api()), scale: 2);
    expect(tester.takeException(), isNull);
  });
  testWidgets('خلاصه مالی پیش از باز کردن جزئیات مستقل نمایش داده می‌شود',
      (tester) async {
    await show(tester, AdminFinanceTab(api: _Api()));
    expect(find.text('۱۲٬۰۰۰٬۰۰۰ تومان'), findsOneWidget);
    expect(find.text('۴۸٬۰۰۰٬۰۰۰ تومان'), findsOneWidget);
    expect(find.text('ریز درآمد، هزینه و حقوق'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await _preview(tester, 'finance');
  });
  testWidgets(
      'جزئیات قبل از باز شدن ساخته نمی‌شوند و با بستن دوباره از بین نمی‌روند',
      (tester) async {
    int initialized = 0;
    await show(
        tester,
        ListView(children: [
          AdminDetailGroup(
              title: 'جزئیات',
              subtitle: 'آزمون',
              icon: Icons.info,
              builder: (_) => _Content(onInit: () => initialized++))
        ]));
    expect(initialized, 0);
    await tester.tap(find.text('جزئیات'));
    await tester.pumpAndSettle();
    expect(initialized, 1);
    await tester.tap(find.text('جزئیات'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('جزئیات'));
    await tester.pumpAndSettle();
    expect(initialized, 1);
  });
}

class _Content extends StatefulWidget {
  const _Content({required this.onInit});
  final VoidCallback onInit;
  @override
  State<_Content> createState() => _ContentState();
}

class _ContentState extends State<_Content> {
  @override
  void initState() {
    super.initState();
    widget.onInit();
  }

  @override
  Widget build(BuildContext context) => const Text('محتوا');
}
