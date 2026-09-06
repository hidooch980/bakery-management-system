import 'package:bakery_app/screens/shared/error_log_screen.dart';
import 'package:bakery_app/services/error_log.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Every failure in this app used to go nowhere.
///
/// A widget that threw was drawn as a grey rectangle with no text. An
/// error outside a build reached the console, which on a handset in a
/// shop is nobody. So the only report that could ever come back was «کار
/// نکرد» — and placing one of those cost five releases, a photograph and
/// a round of questions, while the message that named the file and the
/// type had existed the whole time.
void main() {
  setUp(ErrorLog.clear);

  test('the newest is first, because it is the one being asked about', () {
    ErrorLog.record('اولی');
    ErrorLog.record('دومی');

    expect(ErrorLog.entries.value.first.message, 'دومی');
  });

  test('it keeps a handful and not a history', () {
    for (var i = 0; i < ErrorLog.capacity + 10; i++) {
      ErrorLog.record('خطای $i');
    }

    expect(ErrorLog.entries.value, hasLength(ErrorLog.capacity));

    // The oldest go, not the newest: a phone left open all day must not
    // push out the failure that happened a minute ago.
    expect(ErrorLog.entries.value.first.message, 'خطای 29');
  });

  test('where it happened is kept, since that is half the answer', () {
    ErrorLog.record('نوع اشتباه', where: 'هنگام رسم صفحه');

    expect(ErrorLog.entries.value.single.where, 'هنگام رسم صفحه');
  });

  testWidgets('the screen says so plainly when there is nothing',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: ErrorLogScreen()));

    expect(find.textContaining('خطایی ثبت نشده'), findsOneWidget);
  });

  testWidgets('and shows the message as the machine wrote it',
      (tester) async {
    // Verbatim, in the language it was written in: translating loses the
    // file and the type, which are the two things worth having.
    ErrorLog.record(
      "type 'List<dynamic>' is not a subtype of type 'Map<dynamic, dynamic>?'",
      where: 'هنگام رسم صفحه',
    );

    await tester.pumpWidget(const MaterialApp(home: ErrorLogScreen()));
    await tester.pump();

    expect(find.textContaining("is not a subtype"), findsOneWidget);
    expect(find.textContaining('هنگام رسم صفحه'), findsOneWidget);
  });

  test('a failed request does not put the login token on the screen', () {
    // This log is read out loud and photographed — that is the whole
    // point of it — so anything it holds is as public as the shop's
    // counter. Dio prints only its type and message today; if that ever
    // widens to the request, the token travels with it.
    ErrorLog.record(
      DioException(
        requestOptions: RequestOptions(
          path: '/sales',
          baseUrl: 'http://server.test/api/v1',
          headers: {'Authorization': 'Bearer SECRET-TOKEN-12345'},
        ),
        message: 'boom',
      ),
    );

    expect(
      ErrorLog.entries.value.single.message,
      isNot(contains('SECRET-TOKEN-12345')),
      reason: 'the token reached a screen meant to be photographed',
    );
  });
}
