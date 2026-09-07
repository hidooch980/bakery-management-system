import 'dart:typed_data';

import 'package:bakery_app/models/entries.dart';
import 'package:bakery_app/screens/shared/my_attendance_screen.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// «این ماه چند روز آمده‌ام» had no answer on the phone.
///
/// `/attendance/my-history` and `attendanceHistory()` both existed and
/// nothing called either, so a baker had to ask the owner, who had to open
/// the panel on a computer. This is the screen that asks.
Future<void> _pump(WidgetTester tester, String body) async {
  SharedPreferences.setMockInitialValues({});
  FlutterSecureStorage.setMockInitialValues({});

  final client = ApiClient(baseUrl: 'http://server.test/api/v1');
  client.useAdapterForTest(_Wire(body));

  await tester.pumpWidget(MaterialApp(
    home: MyAttendanceScreen(api: BakeryApi(client)),
  ));
  await tester.pumpAndSettle();
}

String _envelope(String rows) =>
    '{"success":true,"data":{"current_page":1,"data":[$rows]}}';

String _row(int id, String date, String? at) =>
    '{"id":$id,"date":"$date","checked_in_at":'
    '${at == null ? 'null' : '"$at"'}}';

void main() {
  group('a row that came back short', () {
    // The model used `DateTime.parse`, which throws. A throw during build
    // is a grey rectangle in release with no text on it, so one row with a
    // missing time would have taken the whole history off the screen.
    test('a missing date is null rather than an exception', () {
      final record = AttendanceRecord.fromJson(const {'id': 4});

      expect(record.date, isNull);
      expect(record.checkedInAt, isNull);
    });

    test('an unparseable date is null too', () {
      final record =
          AttendanceRecord.fromJson(const {'id': 4, 'date': 'دیروز'});

      expect(record.date, isNull);
    });

    test('a row with nothing at all still has an id', () {
      expect(AttendanceRecord.fromJson(const {}).id, 0);
    });
  });

  group('the screen', () {
    testWidgets('says so plainly when nobody has ticked in yet',
        (tester) async {
      await _pump(tester, _envelope(''));

      expect(tester.takeException(), isNull);
      expect(find.textContaining('هنوز حضوری ثبت نشده'), findsOneWidget);
    });

    testWidgets('draws the days and the time each one was ticked',
        (tester) async {
      await _pump(tester, _envelope([
        _row(3, '2026-09-07', '2026-09-07T05:40:00'),
        _row(2, '2026-09-06', '2026-09-06T05:35:00'),
      ].join(',')));

      expect(tester.takeException(), isNull);
      expect(find.text('05:40'), findsOneWidget);
      expect(find.text('05:35'), findsOneWidget);
    });

    testWidgets('counts the days in the month the newest one belongs to',
        (tester) async {
      // Two in Shahrivar and one in the month before. Counting the list
      // would say three, which answers a question nobody asked.
      await _pump(tester, _envelope([
        _row(3, '2026-09-07', '2026-09-07T05:40:00'),
        _row(2, '2026-09-06', '2026-09-06T05:35:00'),
        _row(1, '2026-08-01', '2026-08-01T05:30:00'),
      ].join(',')));

      // The rows themselves name their month too, so the summary is
      // matched whole rather than by the month name alone.
      expect(find.textContaining('2 روز در شهریور'), findsOneWidget);
    });

    testWidgets('a day with no recorded time still counts as a day',
        (tester) async {
      await _pump(tester, _envelope(_row(9, '2026-09-07', null)));

      expect(tester.takeException(), isNull);
      // The row is there and says which day it was; only the chip is gone.
      expect(find.textContaining('1 روز'), findsOneWidget);
      expect(find.byType(Chip), findsNothing);
    });

    testWidgets('a row missing its date does not take the screen with it',
        (tester) async {
      await _pump(
        tester,
        _envelope('{"id":5,"checked_in_at":"2026-09-07T05:40:00"}'),
      );

      expect(tester.takeException(), isNull);
      expect(find.byType(Card), findsWidgets);
    });
  });
}

class _Wire implements HttpClientAdapter {
  _Wire(this.body);

  final String body;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async =>
      ResponseBody.fromString(body, 200, headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      });

  @override
  void close({bool force = false}) {}
}
