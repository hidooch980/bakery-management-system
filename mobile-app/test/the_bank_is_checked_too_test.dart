import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// The phone side of asking a bank account the drawer's question.
///
/// «حساب سفید» drifted 70,292,603 Toman from the bank and was closed with
/// a hand-typed withdrawal labelled «برداشت شخصی: اختلاف» — the gap gone
/// from the screen, the cause never found. A month later it had reopened.
/// A gap one week wide can be traced; one three months wide cannot.
///
/// What the server cannot show is the half that lives here: that naming
/// no account still means the till, so every phone already in the shop
/// keeps working; that the account actually reaches the request; and that
/// two accounts counted in one sitting are not sent under one name, which
/// the server would read as a replay and drop.
class _Wire implements HttpClientAdapter {
  _Wire(this.answer);

  final ResponseBody Function(RequestOptions options) answer;
  final List<RequestOptions> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options);

    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _ok(Object data) => ResponseBody.fromString(
      jsonEncode({'success': true, 'data': data}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

Map<String, dynamic> _countPayload() => {
      'id': 1,
      'counted_formatted': '۲۰٬۶۰۳٬۲۲۵ تومان',
      'expected_formatted': '۷٬۵۸۰٬۳۷۵ تومان',
      'difference_formatted': '۱۳٬۰۲۲٬۸۵۰ تومان',
      'difference_label': 'اضافه',
      'is_exact': false,
      'adjusted': false,
      'counted_at': '۱۴۰۵/۰۶/۲۴',
      'counted_by': 'عبدالناصر',
      'note': null,
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  ({BakeryApi api, _Wire wire}) apiThat(
    ResponseBody Function(RequestOptions) answer,
  ) {
    final client = ApiClient(baseUrl: 'http://test.local');
    final wire = _Wire(answer);
    client.transport = wire;

    return (api: BakeryApi(client), wire: wire);
  }

  test('the accounts worth asking about reach the screen', () async {
    final it = apiThat((_) => _ok({
          'expected_formatted': '۷٬۵۸۰٬۳۷۵ تومان',
          'account': {'id': 1, 'title': 'حساب سفید', 'is_cash_box': false},
          'accounts': [
            {'id': 2, 'title': 'صندوق نقد', 'is_cash_box': true},
            {'id': 1, 'title': 'حساب سفید', 'is_cash_box': false},
          ],
          'counts': const [],
        }));

    final book = await it.api.cashCounts(accountId: 1);

    expect(book.account!.title, 'حساب سفید');
    expect(book.account!.isCashBox, isFalse);
    expect(book.accounts.map((a) => a.title), ['صندوق نقد', 'حساب سفید']);
  });

  test('an older server that sends no accounts leaves the list empty',
      () async {
    // The phone updates before the shop's server does. A missing key has
    // to read as «no choice offered», not as a crash on the one screen
    // somebody opened to check their money.
    final it = apiThat((_) => _ok({
          'expected_formatted': '۵٬۰۰۰٬۰۰۰ تومان',
          'counts': const [],
        }));

    final book = await it.api.cashCounts();

    expect(book.accounts, isEmpty);
    expect(book.account, isNull);
    expect(book.expectedFormatted, '۵٬۰۰۰٬۰۰۰ تومان');
  });

  test('the named account travels with the read', () async {
    final it = apiThat((_) => _ok({
          'expected_formatted': '۰',
          'counts': const [],
        }));

    await it.api.cashCounts(accountId: 4);

    expect(it.wire.seen.single.queryParameters['account_id'], '4');
  });

  test('naming no account asks for nothing, so the server picks the till',
      () async {
    final it = apiThat((_) => _ok({
          'expected_formatted': '۰',
          'counts': const [],
        }));

    await it.api.cashCounts();

    expect(it.wire.seen.single.queryParameters.containsKey('account_id'),
        isFalse);
  });

  test('the account is sent with the count', () async {
    final it = apiThat((_) => _ok(_countPayload()));

    await it.api.recordCashCount(
      countedAmount: 20603225,
      accountId: 1,
      attemptKey: 'abc-1',
    );

    final body = it.wire.seen.single.data as Map;

    expect(body['account_id'], 1);
    expect(body['counted_amount'], 20603225);
  });

  test('a count with no account named carries no account_id at all', () async {
    // Not `account_id: null` — the server reads «absent» as the till, and
    // an explicit null would have to be special-cased there instead.
    final it = apiThat((_) => _ok(_countPayload()));

    await it.api.recordCashCount(countedAmount: 1000, attemptKey: 'abc-0');

    expect((it.wire.seen.single.data as Map).containsKey('account_id'),
        isFalse);
  });

  test('the gap comes back worded by the server, not re-derived here',
      () async {
    final it = apiThat((_) => _ok(_countPayload()));

    final count = await it.api.recordCashCount(
      countedAmount: 20603225,
      accountId: 1,
    );

    expect(count.differenceLabel, 'اضافه');
    expect(count.differenceFormatted, '۱۳٬۰۲۲٬۸۵۰ تومان');
    expect(count.isExact, isFalse);
    expect(count.adjusted, isFalse);
  });

  test('two accounts counted in one sitting are sent under different names',
      () async {
    // The sheet mints one name per opening. Two real counts under one
    // name would read as a retry, and the server would drop the second
    // rather than record it — worse than writing it twice.
    final it = apiThat((_) => _ok(_countPayload()));

    await it.api.recordCashCount(
      countedAmount: 1000,
      attemptKey: 'opening-0',
    );
    await it.api.recordCashCount(
      countedAmount: 2000,
      accountId: 1,
      attemptKey: 'opening-1',
    );

    final names = it.wire.seen
        .map((r) => r.headers['Idempotency-Key'])
        .toList();

    expect(names.first, isNot(names.last));
  });
}
