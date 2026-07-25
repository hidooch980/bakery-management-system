import '../models/entries.dart';
import '../models/user.dart';
import 'api_client.dart';

/// Typed wrapper over every endpoint the mobile app uses.
class BakeryApi {
  BakeryApi(this._client);

  final ApiClient _client;

  ApiClient get client => _client;

  // ---------------------------------------------------------------- auth

  Future<({String token, AppUser user})> login(
      String login, String password) async {
    final body = await _client.post('/login', {
      'login': login,
      'password': password,
    });

    final data = body['data'] as Map<String, dynamic>;
    final token = data['token'] as String;

    await _client.saveToken(token);

    return (
      token: token,
      user: AppUser.fromJson(data['user'] as Map<String, dynamic>),
    );
  }

  Future<AppUser> me() async {
    final body = await _client.get('/me');
    return AppUser.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<void> logout() async {
    try {
      await _client.post('/logout');
    } finally {
      // Always drop the local token, even if the server call failed.
      await _client.clearToken();
    }
  }

  Future<String> changePassword({
    required String current,
    required String next,
  }) async {
    final body = await _client.post('/change-password', {
      'current_password': current,
      'new_password': next,
      'new_password_confirmation': next,
    });

    await _client.clearToken();

    return body['message'] as String? ?? 'رمز عبور تغییر کرد.';
  }

  // ---------------------------------------------------------- attendance

  Future<AttendanceRecord> checkIn() async {
    final body = await _client.post('/attendance/check-in');
    return AttendanceRecord.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<({bool checkedIn, DateTime? at})> attendanceToday() async {
    final body = await _client.get('/attendance/today');
    final data = body['data'] as Map<String, dynamic>;

    return (
      checkedIn: data['checked_in'] as bool? ?? false,
      at: DateTime.tryParse(data['checked_in_at'] as String? ?? ''),
    );
  }

  Future<List<AttendanceRecord>> attendanceHistory() async {
    final body = await _client.get('/attendance/my-history');
    return _paginated(body).map(AttendanceRecord.fromJson).toList();
  }

  // --------------------------------------------------------------- dough

  Future<void> recordDough({required int bagCount, String? note}) =>
      _client.post('/dough-entries', {
        'bag_count': bagCount,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  Future<List<DoughEntry>> myDoughHistory() async {
    final body = await _client.get('/dough-entries/my-history');
    return _paginated(body).map(DoughEntry.fromJson).toList();
  }

  Future<List<DoughEntry>> pendingDough() async {
    final body = await _client.get('/dough-entries/pending');
    return _paginated(body).map(DoughEntry.fromJson).toList();
  }

  // --------------------------------------------------------------- chane

  Future<double> recordChane({
    required int doughEntryId,
    required int chaneCount,
    required double normalWeightKg,
    required double naninoWeightKg,
    required double sprayFlourKg,
  }) async {
    final body = await _client.post('/chane-entries', {
      'dough_entry_id': doughEntryId,
      'chane_count': chaneCount,
      'normal_weight_kg': normalWeightKg,
      'nanino_weight_kg': naninoWeightKg,
      'spray_flour_kg': sprayFlourKg,
    });

    final data = body['data'] as Map<String, dynamic>;
    return double.tryParse('${data['total_weight_kg']}') ?? 0;
  }

  Future<List<ChaneEntry>> myChaneHistory() async {
    final body = await _client.get('/chane-entries/my-history');
    return _paginated(body).map(ChaneEntry.fromJson).toList();
  }

  Future<List<ChaneEntry>> pendingChane() async {
    final body = await _client.get('/chane-entries/pending');
    return _paginated(body).map(ChaneEntry.fromJson).toList();
  }

  // --------------------------------------------------------------- sales

  Future<void> recordSale({
    required int chaneEntryId,
    required PaymentType paymentType,
    double? amount,
    String? note,
  }) =>
      _client.post('/sales', {
        'chane_entry_id': chaneEntryId,
        'payment_type': paymentType.apiValue,
        if (amount != null) 'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  Future<({List<Sale> sales, int count, double total})> todaySales() async {
    final body = await _client.get('/sales/today');
    final data = body['data'] as Map<String, dynamic>;
    final summary = data['summary'] as Map<String, dynamic>;

    return (
      sales: (data['sales'] as List)
          .cast<Map<String, dynamic>>()
          .map(Sale.fromJson)
          .toList(),
      count: (summary['count'] as num).toInt(),
      total: double.tryParse('${summary['total_amount']}') ?? 0,
    );
  }

  // -------------------------------------------------------------- bakery

  Future<Map<String, dynamic>?> bakery() async {
    final body = await _client.get('/bakery');
    return body['data'] as Map<String, dynamic>?;
  }

  /// Laravel paginators nest the rows under `data.data`; plain lists don't.
  List<Map<String, dynamic>> _paginated(Map<String, dynamic> body) {
    final data = body['data'];

    if (data is Map<String, dynamic> && data['data'] is List) {
      return (data['data'] as List).cast<Map<String, dynamic>>();
    }

    if (data is List) return data.cast<Map<String, dynamic>>();

    return const [];
  }
}
