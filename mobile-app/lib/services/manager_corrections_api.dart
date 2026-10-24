import 'api_client.dart';

/// اصلاح‌ها مستقیم به سرور می‌روند؛ نوشتن مالی در صف آفلاین قرار نمی‌گیرد.
class ManagerCorrectionsApi {
  ManagerCorrectionsApi(this.client);
  final ApiClient client;

  Future<Map<String, dynamic>> work(int seller, String kind, int page) async {
    final response =
        await client.get('/seller-work/$seller?${kind}_page=$page');
    final data = response['data'] as Map<String, dynamic>;
    return {
      ...data[kind] as Map<String, dynamic>,
      'currency_label': data['currency_label']
    };
  }

  Future<void> correctWork(int seller, String kind, int? id,
      Map<String, dynamic> data, String attemptKey) async {
    if (id == null) {
      await client.post('/seller-work/$seller/attendance', data, attemptKey);
    } else {
      await client.patch('/seller-work/$seller/$kind/$id', data);
    }
  }

  Future<Map<String, dynamic>> partnerFlour(int id) async {
    final response = await client.get('/manager-corrections/partner-flour/$id');
    return response['data'] as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> partnerRecords(int page) async {
    final response = await client.get('/consignment-flour?page=$page');
    return response['data'] as Map<String, dynamic>;
  }

  Future<List<Map<String, dynamic>>> partners() async {
    final response =
        await client.get('/customers', query: {'partners_only': true});
    return (response['data'] as List).cast<Map<String, dynamic>>();
  }

  Future<void> correctPartnerFlour(int id, Map<String, dynamic> data) =>
      client.patch('/manager-corrections/partner-flour/$id', data);
}
