import 'package:bakery_app/screens/admin/consumption_report_section.dart';
import 'package:bakery_app/screens/admin/production_report_section.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// آرد پاششی جدا از آرد خمیرگیری، به کیسه، در گزارش مصرف و تولید.
class _Api extends BakeryApi {
  _Api() : super(ApiClient(baseUrl: 'https://server.test/api/v1'));

  @override
  Future<Map<String, dynamic>> consumptionSeries({
    required String from,
    required String to,
    String granularity = 'day',
  }) async =>
      {
        'totals': {
          'bags_kneaded': 20,
          'flour_used_kg': 815,
          'flour_used_bags': 20.38,
          'flour_production_kg': 800,
          'flour_production_bags': 20,
          'flour_spray_kg': 15,
          'flour_spray_bags': 0.38,
          'flour_sold_kg': 0,
          'salt_kg': 12.8,
        },
        'rows': [
          {
            'label': '1405/08/07',
            'flour_production_kg': 400,
            'flour_production_bags': 10,
            'flour_spray_kg': 10,
            'flour_spray_bags': 0.25,
          },
          {
            'label': '1405/08/08',
            'flour_production_kg': 400,
            'flour_production_bags': 10,
            'flour_spray_kg': 5,
            'flour_spray_bags': 0.13,
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> productionReport(
          {String? from, String? to}) async =>
      {
        'total_dough_bags': 20,
        'total_dough_entries': 2,
        'total_chane_count': 1800,
        'total_nanino_count': 0,
        'total_normal_weight_kg': 1530,
        'total_spray_flour_kg': 15,
        'total_spray_flour_bags': 0.38,
        'flour': {'dough_bags': 20, 'spray_bags': 0.38},
        'daily': [],
      };
}

Widget _wrap(Widget child) => MaterialApp(
      home: Directionality(
        textDirection: TextDirection.rtl,
        child: Scaffold(body: SingleChildScrollView(child: child)),
      ),
    );

void main() {
  testWidgets('consumption shows dough and dusting flour apart, per period',
      (tester) async {
    await tester.pumpWidget(_wrap(ConsumptionReportSection(
      api: _Api(),
      from: '1405/08/07',
      to: '1405/08/08',
      granularity: 'day',
    )));
    await tester.pumpAndSettle();

    expect(find.text('آرد خمیرگیری'), findsOneWidget);
    expect(find.text('20 کیسه'), findsWidgets);
    expect(find.text('آرد پاششی'), findsOneWidget);
    expect(find.text('0.38 کیسه'), findsOneWidget);
    expect(find.text('20.38 کیسه'), findsOneWidget);
    expect(find.text('خمیرگیری 10 کیسه • پاششی 0.25 کیسه'), findsOneWidget);
    expect(find.text('خمیرگیری 10 کیسه • پاششی 0.13 کیسه'), findsOneWidget);
  });

  testWidgets('production report names both flours in bags', (tester) async {
    await tester.pumpWidget(_wrap(ProductionReportSection(
      api: _Api(),
      from: '1405/08/07',
      to: '1405/08/08',
    )));
    await tester.pumpAndSettle();

    expect(find.text('آرد خمیرگیری'), findsOneWidget);
    expect(find.text('آرد پاششی'), findsOneWidget);
    expect(find.text('0.38 کیسه'), findsOneWidget);
  });
}
