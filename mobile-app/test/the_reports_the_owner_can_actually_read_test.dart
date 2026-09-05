import 'dart:typed_data';

import 'package:bakery_app/models/financial_series.dart';
import 'package:bakery_app/models/today_answer.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// «گزارشات کاملتر و متنوع تر» — and «لینک ها فعال بشه».
///
/// Two complaints that turned out to be the same one. Every figure the
/// owner had on his phone was money: what came in, what went out, who owes
/// what. Nothing said how many sacks were kneaded or how much bread came
/// off the oven, in a bakery — `/reports/production` had answered that
/// since the reports were written and `productionReport()` was defined and
/// called by nothing. And «امروز» listed what needed him without saying
/// why, what to do, or where to go: `SystemIssue` has carried a cause, a
/// suggestion and a link the whole time, and the phone was sent the
/// suggestion alone and drew none of it.
class _Wire implements HttpClientAdapter {
  _Wire(this.bodies);

  final Map<String, String> bodies;

  final asked = <String>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    asked.add(options.path);

    return ResponseBody.fromString(
      bodies[options.path] ?? '{"success":true,"data":{}}',
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});
  });

  BakeryApi apiOver(_Wire wire) {
    final client = ApiClient(baseUrl: 'http://server.test/api/v1');
    client.useAdapterForTest(wire);

    return BakeryApi(client);
  }

  group('what «امروز» now says about a problem', () {
    TodayNeed parse(Map<String, dynamic> json) => TodayNeed.fromJson(json);

    test('it carries the cause and where to deal with it', () {
      final need = parse({
        'key': 'negative-bank-3',
        'severity': 'warning',
        'title': 'موجودی «حساب اصلی» منفی است',
        'detail': 'مانده این حساب ۱۲٬۰۰۰٬۰۰۰− ریال است.',
        'cause': 'برداشتی ثبت شده اما واریز متناظرش وارد نشده است.',
        'suggestion': 'گردش حساب را با دفتر واقعی مقایسه کنید.',
        'destination': 'finance',
      });

      expect(need.cause, contains('واریز متناظرش'));
      expect(need.destination, 'finance');
    });

    /// A build that has not been taught a destination must show no button
    /// rather than one that goes nowhere. The names are chosen on the
    /// server so a new check can point somewhere without an app release,
    /// which only works if an unknown one is harmless.
    test('a destination this build does not know is simply absent', () {
      expect(parse({'key': 'x', 'destination': 'somewhere-new'}).destination,
          'somewhere-new');
      expect(parse({'key': 'x'}).destination, isNull);
    });

    /// The old server sends neither field. The phone must read that as
    /// «nothing more to show», not crash on a missing key.
    test('an older server that sends neither still parses', () {
      final need = parse({
        'key': 'stale-dough',
        'severity': 'info',
        'title': 'خمیر بلاتکلیف',
        'detail': '',
        'suggestion': '',
      });

      expect(need.cause, '');
      expect(need.destination, isNull);
    });
  });

  group('the reports the phone now asks for', () {
    test('production comes back with both systems counted', () async {
      final wire = _Wire({
        '/reports/production': '{"success":true,"data":{'
            '"total_dough_bags":40,"total_dough_entries":10,'
            '"total_chane_count":3800,"total_nanino_count":200,'
            '"total_normal_weight_kg":3230,"total_spray_flour_kg":12,'
            '"daily":[{"date_display":"۱۴۰۵/۰۶/۰۱","total_bread_count":500}]}}',
      });

      final report = await apiOver(wire)
          .productionReport(from: '2026-08-23', to: '2026-09-05');

      expect(report['total_dough_bags'], 40);
      expect(report['total_chane_count'], 3800);
      expect(report['total_nanino_count'], 200);
      expect((report['daily'] as List), hasLength(1));
    });

    test('sales come back split by how they were paid for', () async {
      final wire = _Wire({
        '/reports/sales': '{"success":true,"data":{'
            '"count":12,"bread_count":900,"total_amount":9000000,'
            '"by_payment_type":{"cash":{"label":"نقد","amount":6000000,"bread_count":600},'
            '"credit":{"label":"نسیه","amount":3000000,"bread_count":300}},'
            '"by_seller":[{"seller":"رضا","amount":9000000}]}}',
      });

      final report =
          await apiOver(wire).salesReport(from: '2026-09-05', to: '2026-09-05');

      final byType = (report['by_payment_type'] as Map).cast<String, dynamic>();

      // The label is the server's, not a word the phone invented — the two
      // would otherwise be two places the shop's vocabulary lives.
      expect((byType['credit'] as Map)['label'], 'نسیه');
      expect((byType['cash'] as Map)['amount'], 6000000);
    });

    test('consumption separates flour baked from flour sold on', () async {
      final wire = _Wire({
        '/reports/consumption-series': '{"success":true,"data":{'
            '"totals":{"bags_kneaded":40,"flour_used_kg":1600,'
            '"flour_sold_kg":250,"salt_kg":24},"rows":[]}}',
      });

      final report = await apiOver(wire).consumptionSeries(
        from: '2026-08-23',
        to: '2026-09-05',
      );

      final totals = (report['totals'] as Map).cast<String, dynamic>();

      // The distinction the quota is judged on: flour that left as flour
      // never became bread, and one «مصرف» figure hides which is which.
      expect(totals['flour_used_kg'], 1600);
      expect(totals['flour_sold_kg'], 250);
    });

    /// All three are read through the cache, like every other report, so
    /// they open on a handset with no signal instead of erroring.
    test('they are served from a saved copy when the server cannot be reached',
        () async {
      final wire = _Wire({
        '/reports/production': '{"success":true,"data":{"total_dough_bags":40}}',
      });
      final api = apiOver(wire);

      await api.productionReport(from: '2026-09-01', to: '2026-09-05');

      expect(api.client.servedFrom('/reports/production'), isNull);
    });
  });

  group('the money chart says what the takings were made of', () {
    test('bread, flour and other are added from the buckets', () {
      final series = FinancialSeries.fromJson({
        'totals': {'income': 9000000, 'expense': 4000000},
        'rows': [
          {
            'label': '۱۴۰۵/۰۶/۰۱',
            'income': 5000000,
            'expense': 2000000,
            'income_bread': 4000000,
            'income_flour': 1000000,
            'expense_salaries': 1500000,
          },
          {
            'label': '۱۴۰۵/۰۶/۰۲',
            'income': 4000000,
            'expense': 2000000,
            'income_bread': 3500000,
            'income_other': 500000,
          },
        ],
      });

      expect(series.incomeBread, 7500000);
      expect(series.incomeFlour, 1000000);
      expect(series.incomeOther, 500000);
      expect(series.expenseSalaries, 1500000);

      // The parts must come to the whole, or the rows under the chart
      // contradict the total above them.
      expect(
        series.incomeBread + series.incomeFlour + series.incomeOther,
        series.income,
      );
    });

    /// A server that does not send the split must leave the rows off
    /// rather than draw «۰ از فروش نان» under a month of baking.
    test('a server without the split reports nothing rather than zero rows', () {
      final series = FinancialSeries.fromJson({
        'totals': {'income': 100, 'expense': 40},
        'rows': [
          {'label': 'ب', 'income': 100, 'expense': 40},
        ],
      });

      expect(series.incomeBread, 0);
      expect(series.income, 100);
    });
  });
}
