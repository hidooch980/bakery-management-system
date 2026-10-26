import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../utils/bank_sms_parser.dart';
import '../utils/json.dart';
import 'api_client.dart';

/// The phone half of «پیامک‌های بانکی».
///
/// Android's receiver keeps each new bank SMS in a private queue (it works
/// while the app is closed). [flush] hands that queue to the server and
/// drops only what the server answered for, so a message survives a
/// failed upload, no signal, or the app being killed half way.
class BankSmsNative {
  static const _channel = MethodChannel('bakery/bank_sms');

  static Future<T?> _call<T>(String method, [Object? args]) async {
    try {
      return await _channel.invokeMethod<T>(method, args);
    } on MissingPluginException {
      return null; // not Android, or a test
    } on PlatformException {
      return null;
    }
  }

  static Future<bool> isEnabled() async =>
      await _call<bool>('isEnabled') ?? false;

  static Future<void> setEnabled(bool on) => _call('setEnabled', {'on': on});

  static Future<void> setSenders(List<String> senders) =>
      _call('setSenders', {'senders': senders});

  static Future<bool> hasPermission() async =>
      await _call<bool>('hasPermission') ?? false;

  static Future<bool> requestPermission() async =>
      await _call<bool>('requestPermission') ?? false;

  static Future<List<Map<String, dynamic>>> peek() async {
    final raw = await _call<List<Object?>>('peek') ?? const [];
    return [
      for (final r in raw)
        if (r is Map) r.map((k, v) => MapEntry('$k', v)),
    ];
  }

  static Future<void> remove(List<String> ids) => _call('remove', {'ids': ids});
}

class BankSmsService {
  BankSmsService(this._client);

  final ApiClient _client;

  static const _showOnOpenKey = 'bank_sms_show_on_open';

  /// «بعداً» lasts until the app is closed, not forever.
  static bool dismissedThisSession = false;

  static Future<bool> showOnOpen() async =>
      (await SharedPreferences.getInstance()).getBool(_showOnOpenKey) ?? true;

  static Future<void> setShowOnOpen(bool on) async =>
      (await SharedPreferences.getInstance()).setBool(_showOnOpenKey, on);

  /// Sends whatever the receiver queued. Returns how many are open now,
  /// or null when the server could not be reached.
  Future<int?> flush() async {
    final queued = await BankSmsNative.peek();
    if (queued.isEmpty) return null;

    // Checked again here: whatever the receiver let through, nothing that
    // is not a bank's money message leaves the phone.
    final send = <Map<String, dynamic>>[];
    final drop = <String>[];
    for (final m in queued) {
      final sender = '${m['sender'] ?? ''}';
      final body = '${m['body'] ?? ''}';
      if (BankSmsParser.isOtp(body) ||
          BankSmsParser.parse(sender, body).amountToman == null) {
        drop.add('${m['id']}');
      } else {
        send.add(m);
      }
    }
    if (drop.isNotEmpty) await BankSmsNative.remove(drop);

    int? open;
    for (var i = 0; i < send.length; i += 50) {
      final chunk =
          send.sublist(i, i + 50 > send.length ? send.length : i + 50);
      try {
        final body = await _client.post('/bank-sms', {
          'messages': [
            for (final m in chunk)
              {
                'sender': '${m['sender']}',
                'body': '${m['body']}',
                'received_at': DateTime.fromMillisecondsSinceEpoch(
                        (m['at'] as num).toInt())
                    .toUtc()
                    .toIso8601String(),
              }
          ],
        });
        await BankSmsNative.remove([for (final m in chunk) '${m['id']}']);
        open = ((body['data'] as Map?)?['open'] as num?)?.toInt();
      } on ApiException catch (e) {
        if (e.isConnectivityError) return null;
        // Refused for good (validation): no retry will change that.
        if ((e.statusCode ?? 0) == 422) {
          await BankSmsNative.remove([for (final m in chunk) '${m['id']}']);
        } else {
          return null;
        }
      }
    }
    return open;
  }

  Future<
      ({
        List<Map<String, dynamic>> items,
        int open,
        List<Map<String, dynamic>> balances
      })> list({bool open = true}) async {
    final body = await _client
        .get('/bank-sms', query: {'status': open ? 'open' : 'done'});
    final data = body['data'] as Map<String, dynamic>;
    return (
      items: rowList(data['items']),
      open: (data['open'] as num).toInt(),
      balances: rowList(data['balances']),
    );
  }

  Future<int> count() async {
    final body = await _client.get('/bank-sms/count');
    return ((body['data'] as Map)['open'] as num).toInt();
  }

  /// Throws [ApiException] with status 409 when a twin is on the books;
  /// the second attempt passes [force].
  Future<void> confirm(int id,
          {required String category,
          int? accountId,
          String? note,
          bool force = false}) =>
      _client.post('/bank-sms/$id/confirm', {
        'category': category,
        if (accountId != null) 'bank_account_id': accountId,
        if (note != null && note.isNotEmpty) 'note': note,
        if (force) 'force': true,
      });

  Future<void> ignore(int id) => _client.post('/bank-sms/$id/ignore');

  Future<void> link(int id, int transactionId) => _client
      .post('/bank-sms/$id/link', {'bank_transaction_id': transactionId});

  Future<Map<String, dynamic>> accounts() async =>
      ((await _client.get('/bank-sms/accounts'))['data'] as Map)
          .cast<String, dynamic>();

  Future<void> saveAccount(String last4, int accountId) => _client.post(
      '/bank-sms/accounts', {'last4': last4, 'bank_account_id': accountId});

  Future<void> deleteAccount(int id) =>
      _client.delete('/bank-sms/accounts/$id');
}
