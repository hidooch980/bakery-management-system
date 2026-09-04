import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bakery_app/models/purchase.dart';
import 'package:bakery_app/services/api_client.dart';
import 'package:bakery_app/services/bakery_api.dart';

/// The two writes somebody makes standing in the yard.
///
/// A tanker of diesel and a lorry of flour are both recorded at the gate,
/// off the back of a paper docket, in the one corner of this shop where a
/// phone reliably has no signal. Both used to throw there: the screen said
/// the write failed, and what happened next was a paper note and a promise
/// to type it in later. The warehouse intake beside them on the same
/// screen has been queueing since the day it was written.
///
/// The offline queue only ever helps the endpoints that opt in, which is
/// why the test is about which ones do.
class _NoSignal implements HttpClientAdapter {
  final List<RequestOptions> seen = [];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    seen.add(options);

    throw DioException.connectionError(
      requestOptions: options,
      reason: 'no route to host',
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late BakeryApi api;
  late ApiClient client;

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    FlutterSecureStorage.setMockInitialValues({});

    client = ApiClient(baseUrl: 'http://test.local');
    client.transport = _NoSignal();
    api = BakeryApi(client);
  });

  group('a tanker recorded with nothing to send it to', () {
    test('it is kept rather than refused', () async {
      final outcome = await api.recordDieselDelivery(
        litres: 400,
        amount: 120000000,
        docketNumber: '۲۲۷۸',
      );

      expect(outcome.queued, isTrue);

      final held = await client.queue.all();

      expect(held, hasLength(1));
      expect(held.first.path, '/diesel/deliveries');
      expect(held.first.body['litres'], 400);
      expect(held.first.body['docket_number'], '۲۲۷۸');
    });

    test('it says nothing about the quota it cannot have counted', () async {
      final outcome = await api.recordDieselDelivery(litres: 400);

      // Whether this load went over the month is arithmetic over every
      // other delivery, and those live on the server. A warning invented
      // here — or the absence of one read as «همه‌چیز مرتب است» — is a
      // guess made at the moment somebody is deciding whether to sign.
      expect(outcome.warning, isNull);
      expect(outcome.quota, isNull);
    });

    test('the pending list names it in litres', () async {
      await api.recordDieselDelivery(litres: 400);

      expect((await client.queue.all()).first.label, contains('گازوئیل'));
      expect((await client.queue.all()).first.label, contains('400'));
    });
  });

  group('a delivery of flour written down at the gate', () {
    test('the invoice waits instead of being lost', () async {
      final outcome = await api.recordPurchase(
        lines: [
          PurchaseLineDraft(
            itemKey: 'flour',
            bags: 50,
            unitPrice: 4200000,
          ),
        ],
        supplierName: 'آسیاب شرق',
        invoiceNo: '۹۹۱',
        paidAmount: 0,
      );

      expect(outcome.queued, isTrue);
      expect(outcome.purchase, isNull);

      final held = await client.queue.all();

      expect(held, hasLength(1));
      expect(held.first.path, '/purchases');
      expect((held.first.body['items'] as List), hasLength(1));
      expect(held.first.body['supplier_name'], 'آسیاب شرق');
    });

    test('the pending list names the mill, not the row id', () async {
      await api.recordPurchase(
        lines: [PurchaseLineDraft(itemKey: 'flour', bags: 50, unitPrice: 4200000)],
        supplierName: 'آسیاب شرق',
      );

      // The person reading the pending list is the person who typed it.
      // «فاکتور خرید #4» tells them nothing about which lorry that was.
      expect((await client.queue.all()).first.label, contains('آسیاب شرق'));
    });

    test('a mill already on file is named by its invoice number', () async {
      await api.recordPurchase(
        lines: [PurchaseLineDraft(itemKey: 'flour', bags: 50, unitPrice: 4200000)],
        supplierId: 3,
        invoiceNo: '۹۹۱',
      );

      // Only the id went to the server, so the name is not ours to print.
      // The number on the paper docket is the next best handle.
      expect((await client.queue.all()).first.label, contains('۹۹۱'));
    });
  });

  test('each write carries its own name, so a replay is recognised', () async {
    await api.recordDieselDelivery(litres: 400);
    await api.recordPurchase(
      lines: [PurchaseLineDraft(itemKey: 'flour', bags: 50, unitPrice: 4200000)],
      supplierName: 'آسیاب شرق',
    );

    final ids = (await client.queue.all()).map((r) => r.id).toSet();

    expect(ids, hasLength(2));
  });
}
