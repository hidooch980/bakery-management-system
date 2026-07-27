import '../models/bakery.dart';
import '../models/chane_board.dart';
import '../models/customer.dart';
import '../models/entries.dart';
import '../models/flour_sale.dart';
import '../models/ledger_entry.dart';
import '../models/seller_account.dart';
import '../models/settlement_request.dart';
import '../models/user.dart';
import '../models/work_start.dart';
import 'api_client.dart';
import 'offline_queue.dart';

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

  /// Queued for a later sync when there is no signal — a check-in with no
  /// server-assigned time yet still counts as "I was here", so it must
  /// never be blocked by connectivity.
  Future<({AttendanceRecord? record, bool queued})> checkIn() async {
    final body = await _client.postOrQueue(
      '/attendance/check-in',
      const {},
      label: 'حضور و غیاب',
    );

    if (body['queued'] == true) return (record: null, queued: true);

    return (
      record: AttendanceRecord.fromJson(body['data'] as Map<String, dynamic>),
      queued: false,
    );
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

  /// Returns true when there was no signal and the entry was saved to the
  /// offline queue instead of sent — the caller shows a different message
  /// but the flow is otherwise identical.
  Future<bool> recordDough({required int bagCount, String? note}) async {
    final body = await _client.postOrQueue(
      '/dough-entries',
      {
        'bag_count': bagCount,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'خمیر — $bagCount کیسه',
    );

    return body['queued'] == true;
  }

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
  ///
  /// [weightKg] is null when the entry was queued offline: the formula
  /// lives on the server, so there is no weight to show until it syncs.
  /// Records a batch of chane. Pass [trays] when it was counted out tray by
  /// tray, which is how the shop floor works; the server adds them up
  /// itself so the total can never disagree with the trays behind it.
  Future<({double? weightKg, bool queued})> recordChane({
    required int doughEntryId,
    required int chaneCount,
    int naninoChaneCount = 0,
    required double sprayFlourKg,
    List<int>? trays,
  }) async {
    final body = await _client.postOrQueue(
      '/chane-entries',
      {
        'dough_entry_id': doughEntryId,
        'chane_count': chaneCount,
        'nanino_chane_count': naninoChaneCount,
        'spray_flour_kg': sprayFlourKg,
        if (trays != null && trays.isNotEmpty) 'trays': trays,
      },
      label: 'چانه — $chaneCount عدد',
    );

    if (body['queued'] == true) return (weightKg: null, queued: true);

    final data = body['data'] as Map<String, dynamic>;
    return (
      weightKg: double.tryParse('${data['total_weight_kg']}') ?? 0,
      queued: false,
    );
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

  /// The chane batch being sold was already fetched from the server (the
  /// seller only ever sees pending chane loaded while online), so its id
  /// is always real — queueing the sale itself offline is safe.
  Future<bool> recordSale({
    required int chaneEntryId,
    required PaymentType paymentType,
    int? breadCount,
    int? customerId,
    double? amount,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/sales',
      {
        'chane_entry_id': chaneEntryId,
        'payment_type': paymentType.apiValue,
        if (breadCount != null) 'bread_count': breadCount,
        if (customerId != null) 'customer_id': customerId,
        if (amount != null) 'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'فروش — چانه #$chaneEntryId',
    );

    return body['queued'] == true;
  }

  /// Records one batch paid for in several ways at once — part cash, part
  /// card — as a single request, so the batch is closed once and any
  /// shortfall is counted once rather than per line.
  Future<bool> recordSplitSale({
    required int chaneEntryId,
    required List<SalePaymentLine> payments,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/sales',
      {
        'chane_entry_id': chaneEntryId,
        'payments': payments.map((line) => line.toJson()).toList(),
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'فروش — چانه #$chaneEntryId',
    );

    return body['queued'] == true;
  }

  /// Schools and offices the admin has defined, for attributing a sale.
  Future<List<Customer>> customers() async {
    final body = await _client.get('/customers');

    return (body['data'] as List)
        .cast<Map<String, dynamic>>()
        .map(Customer.fromJson)
        .toList();
  }

  /// The seller's own temporary account — what they still answer for.
  Future<SellerAccount> myAccount() async {
    final body = await _client.get('/sales/my-account');

    return SellerAccount.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// Asks the admin to confirm the seller has handed their account over.
  /// The account only clears once the admin agrees, so this returns the
  /// request rather than a settled balance.
  Future<SettlementRequest> requestSettlement({String? note}) async {
    final body = await _client.post('/settlement-requests', {
      if (note != null && note.isNotEmpty) 'note': note,
    });

    return SettlementRequest.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// The seller's own settlement requests: the one awaiting an answer, if
  /// any, and what happened to the ones before it.
  Future<({SettlementRequest? pending, List<SettlementRequest> history})>
      settlementRequests() async {
    final body = await _client.get('/settlement-requests');
    final data = body['data'] as Map<String, dynamic>;

    return (
      pending: data['pending'] == null
          ? null
          : SettlementRequest.fromJson(data['pending'] as Map<String, dynamic>),
      history: ((data['history'] as List?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(SettlementRequest.fromJson)
          .toList(),
    );
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
  /// [sale] is null when queued offline — the warehouse balance check that
  /// guards this endpoint runs on the server, so a queued sale is only
  /// checked against stock once it actually syncs.
  Future<({FlourSale? sale, bool queued})> recordFlourSale({
    required FlourUnit unit,
    required double quantity,
    required PaymentType paymentType,
    double? unitPrice,
    int? customerId,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/flour-sales',
      {
        'unit': unit.apiValue,
        'quantity': quantity,
        'payment_type': paymentType.apiValue,
        if (unitPrice != null) 'unit_price': unitPrice,
        if (customerId != null) 'customer_id': customerId,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'فروش آرد — ${unit.label}',
    );

    if (body['queued'] == true) return (sale: null, queued: true);

    return (
      sale: FlourSale.fromJson(body['data'] as Map<String, dynamic>),
      queued: false,
    );
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

  // ---------------------------------------------------------- work start

  /// Today's start board for shaping and baking.
  Future<WorkStartBoard> workStartBoard() async {
    final body = await _client.get('/work-starts/today');

    return WorkStartBoard.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// Ticks the start of an activity. Lateness is decided server-side against
  /// the configured deadline, never by the phone's clock — so when this is
  /// queued offline, whether it was late is only known once it syncs.
  Future<({WorkStartBoard? board, bool isLate, String? warning, bool queued})>
      recordWorkStart(WorkStartType type) async {
    final body = await _client.postOrQueue(
      '/work-starts',
      {'type': type.apiValue},
      label: type.label,
    );

    if (body['queued'] == true) {
      return (board: null, isLate: false, warning: null, queued: true);
    }

    final data = body['data'] as Map<String, dynamic>;

    return (
      board: WorkStartBoard.fromJson(data['board'] as Map<String, dynamic>),
      isLate: data['is_late'] == true,
      warning: data['warning'] as String?,
      queued: false,
    );
  }

  /// Late starts over a period, for payroll.
  Future<Map<String, dynamic>> workStartLateReport({String? from, String? to}) async {
    final body = await _client.get('/work-starts/late-report', query: {
      if (from != null) 'from': from,
      if (to != null) 'until': to,
    });

    return body['data'] as Map<String, dynamic>;
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


  // ----------------------------------------------- admin: money and stock

  /// Categories an expense can be filed under, as the shop defines them.
  Future<List<LedgerCategory>> expenseCategories() async {
    final body = await _client.get('/expenses/categories');

    return (body['data'] as List)
        .cast<Map<String, dynamic>>()
        .map(LedgerCategory.fromJson)
        .toList();
  }

  Future<List<LedgerCategory>> incomeCategories() async {
    final body = await _client.get('/incomes/categories');

    return (body['data'] as List)
        .cast<Map<String, dynamic>>()
        .map(LedgerCategory.fromJson)
        .toList();
  }

  /// Records money paid out. Queued when offline, like every other entry,
  /// so the admin can record a purchase standing at the supplier.
  Future<bool> recordExpense({
    required String category,
    required String title,
    required double amount,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/expenses',
      {
        'category': category,
        'title': title,
        'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'هزینه — $title',
    );

    return body['queued'] == true;
  }

  Future<bool> recordIncome({
    required String category,
    required String title,
    required double amount,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/incomes',
      {
        'category': category,
        'title': title,
        'amount': amount,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'درآمد — $title',
    );

    return body['queued'] == true;
  }

  /// Flour bought into the warehouse, recorded in sacks rather than kilos
  /// because that is how it arrives.
  Future<bool> recordFlourIntake({
    required double bags,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/inventory/movements',
      {
        'item': 'flour',
        'direction': 'in',
        'bags': bags,
        'reason': 'purchase',
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'ورود آرد — $bags کیسه',
    );

    return body['queued'] == true;
  }

  /// Flour lent to or borrowed from another bakery.
  Future<bool> recordConsignmentFlour({
    required String partnerName,
    required String direction,
    required double amountKg,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/consignment-flour',
      {
        'partner_name': partnerName,
        'direction': direction,
        'amount_kg': amountKg,
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'آرد امانی — $partnerName',
    );

    return body['queued'] == true;
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

  // -------------------------------------------------------- offline sync

  /// Entries recorded with no signal, waiting to be sent.
  Future<List<QueuedRequest>> pendingSync() => _client.queue.all();

  Future<int> pendingSyncCount() => _client.queue.count();

  /// Resends everything queued. Safe to call whenever — with nothing
  /// queued it is a no-op, and mid-sync it just picks up where it left off.
  Future<({int sent, int failed, int remaining})> syncPending() =>
      _client.syncQueue();

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
