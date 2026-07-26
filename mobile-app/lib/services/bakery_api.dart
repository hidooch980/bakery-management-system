import '../models/bakery.dart';
import '../models/chane_board.dart';
import '../models/customer.dart';
import '../models/entries.dart';
import '../models/flour_sale.dart';
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

  /// Records chane for a dough batch. Weights are derived server-side from
  /// the admin's dough formula, so only counts are sent.
  Future<double> recordChane({
    required int doughEntryId,
    required int chaneCount,
    int naninoChaneCount = 0,
    required double sprayFlourKg,
  }) async {
    final body = await _client.post('/chane-entries', {
      'dough_entry_id': doughEntryId,
      'chane_count': chaneCount,
      'nanino_chane_count': naninoChaneCount,
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
    int? breadCount,
    int? customerId,
    double? amount,
    String? note,
  }) =>
      _client.post('/sales', {
        'chane_entry_id': chaneEntryId,
        'payment_type': paymentType.apiValue,
        if (breadCount != null) 'bread_count': breadCount,
        if (customerId != null) 'customer_id': customerId,
        if (amount != null) 'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  /// Schools and offices the admin has defined, for attributing a sale.
  Future<List<Customer>> customers() async {
    final body = await _client.get('/customers');

    return (body['data'] as List)
        .cast<Map<String, dynamic>>()
        .map(Customer.fromJson)
        .toList();
  }

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

  // --------------------------------------------------------- flour sales

  /// The going rates and what is left in the warehouse.
  Future<FlourSaleOptions> flourSaleOptions() async {
    final body = await _client.get('/flour-sales/options');

    return FlourSaleOptions.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// Sells flour by the kilo or by the sack. The weight and the total are
  /// worked out server-side, so only the quantity and rate go up.
  Future<FlourSale> recordFlourSale({
    required FlourUnit unit,
    required double quantity,
    required PaymentType paymentType,
    double? unitPrice,
    int? customerId,
    String? note,
  }) async {
    final body = await _client.post('/flour-sales', {
      'unit': unit.apiValue,
      'quantity': quantity,
      'payment_type': paymentType.apiValue,
      if (unitPrice != null) 'unit_price': unitPrice,
      if (customerId != null) 'customer_id': customerId,
      if (note != null && note.isNotEmpty) 'note': note,
    });

    return FlourSale.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<
      ({
        List<FlourSale> sales,
        int count,
        double totalWeightKg,
        String totalFormatted,
      })> todayFlourSales() async {
    final body = await _client.get('/flour-sales/today');
    final data = body['data'] as Map<String, dynamic>;
    final summary = data['summary'] as Map<String, dynamic>;

    return (
      sales: (data['sales'] as List)
          .cast<Map<String, dynamic>>()
          .map(FlourSale.fromJson)
          .toList(),
      count: (summary['count'] as num?)?.toInt() ?? 0,
      totalWeightKg:
          double.tryParse('${summary['total_weight_kg']}') ?? 0,
      totalFormatted: summary['total_amount_formatted'] as String? ?? '',
    );
  }

  // --------------------------------------------------------------- board

  Future<ChaneBoard> chaneBoard() async {
    final body = await _client.get('/chane-board');

    return ChaneBoard.fromJson(body['data'] as Map<String, dynamic>);
  }

  // --------------------------------------------------------------- admin

  /// Admin dashboard counters for today.
  Future<Map<String, dynamic>> dashboard() async {
    final body = await _client.get('/reports/dashboard');

    return body['data'] as Map<String, dynamic>;
  }

  /// Income against expenses, with profit, for a date range.
  Future<Map<String, dynamic>> financialReport({String? from, String? to}) async {
    final body = await _client.get('/reports/financial', query: {
      if (from != null) 'from': from,
      if (to != null) 'to': to,
    });

    return body['data'] as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> productionReport({String? from, String? to}) async {
    final body = await _client.get('/reports/production', query: {
      if (from != null) 'from': from,
      if (to != null) 'to': to,
    });

    return body['data'] as Map<String, dynamic>;
  }

  /// Current stock levels for flour, salt and dough.
  Future<List<Map<String, dynamic>>> inventory() async {
    final body = await _client.get('/inventory');

    return (body['data'] as List).cast<Map<String, dynamic>>();
  }

  /// The flour quota for today's delivery period, or null if none is set.
  Future<Map<String, dynamic>?> currentFlourAllocation() async {
    final body = await _client.get('/flour-allocations/current');

    return body['data'] as Map<String, dynamic>?;
  }

  /// Who checked in today, with their times.
  Future<List<Map<String, dynamic>>> adminAttendanceToday() async {
    final body = await _client.get('/reports/attendance');

    return _paginated(body);
  }

  // -------------------------------------------------------------- bakery

  Future<Bakery?> bakery() async {
    final body = await _client.get('/bakery');
    final data = body['data'] as Map<String, dynamic>?;

    return data == null ? null : Bakery.fromJson(data);
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
