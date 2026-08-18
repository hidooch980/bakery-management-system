import '../models/bakery.dart';
import '../models/balance_sheet.dart';
import '../models/bank_account.dart';
import '../models/financial_series.dart';
import '../models/chane_board.dart';
import '../models/customer.dart';
import '../models/entries.dart';
import '../models/payroll.dart';
import '../models/staff_adjustment.dart';
import '../models/flour_sale.dart';
import '../models/ledger_entry.dart';
import '../models/seller_account.dart';
import '../models/settlement_request.dart';
import '../models/user.dart';
import '../models/work_start.dart';
import 'api_client.dart';
import 'offline_queue.dart';
import '../models/quota_and_advance.dart';

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
    await _client.clearCache();
    }
  }

  /// Asks for a six-digit code by text.
  ///
  /// Answers the same way whether the number is registered or not — the
  /// server refuses to say, so that this cannot be used to find out who
  /// works at the shop. Nothing here should try to interpret it otherwise.
  Future<void> requestPasswordCode(String phone) =>
      _client.post('/forgot-password', {'phone': phone});

  /// The code, and a new password.
  Future<void> resetPasswordWithCode({
    required String phone,
    required String code,
    required String password,
  }) =>
      _client.post('/reset-password', {
        'phone': phone,
        'code': code,
        'password': password,
        'password_confirmation': password,
      });

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
    await _client.clearCache();

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

  /// The rest of the floor and whether each of them is in yet.
  Future<List<StaffAttendance>> attendanceRoster() async {
    final body = await _client.get('/attendance/roster');
    final data = body['data'] as Map<String, dynamic>;

    return ((data['staff'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(StaffAttendance.fromJson)
        .toList();
  }

  /// Records someone else's arrival, for the ones working with flour on
  /// their hands and a phone in a locker.
  ///
  /// Queued like your own check-in: no signal must not cost someone their
  /// day's attendance.
  Future<bool> checkInFor(int userId) async {
    final body = await _client.postOrQueue(
      '/attendance/check-in/$userId',
      const {},
      label: 'حضور و غیاب',
    );

    return body['queued'] == true;
  }

  Future<({bool checkedIn, DateTime? at})> attendanceToday() async {
    final body = await _client.getCached('/attendance/today');
    final data = body['data'] as Map<String, dynamic>;

    return (
      checkedIn: data['checked_in'] as bool? ?? false,
      at: DateTime.tryParse(data['checked_in_at'] as String? ?? ''),
    );
  }

  Future<List<AttendanceRecord>> attendanceHistory() async {
    final body = await _client.getCached('/attendance/my-history');
    return _paginated(body).map(AttendanceRecord.fromJson).toList();
  }

  // --------------------------------------------------------------- dough

  /// Records a batch. Returns true when it went into the offline queue
  /// rather than to the server.
  ///
  /// [force] repeats a batch the server refused as a double tap. It is set
  /// only after the person has been shown what they already recorded and
  /// said this is a second one — never automatically, which would put the
  /// guard back where it started.
  Future<bool> recordDough({
    required int bagCount,
    String? note,
    bool force = false,
  }) async {
    final body = await _client.postOrQueue(
      '/dough-entries',
      {
        'bag_count': bagCount,
        if (note != null && note.isNotEmpty) 'note': note,
        if (force) 'force': true,
      },
      label: 'خمیر — $bagCount کیسه',
    );

    return body['queued'] == true;
  }

  Future<List<DoughEntry>> myDoughHistory() async {
    final body = await _client.getCached('/dough-entries/my-history');
    return _paginated(body).map(DoughEntry.fromJson).toList();
  }

  Future<List<DoughEntry>> pendingDough() async {
    final body = await _client.getCached('/dough-entries/pending');
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

  // ------------------------------------------------ reward and penalty

  /// Writes down one reward or one penalty on the day it happened.
  ///
  /// [basis] is 'amount', 'days' or 'note'. A reason is required by the
  /// server and refused if missing: a deduction nobody can explain a month
  /// later is one the person it was taken from will dispute.
  Future<StaffAdjustment> recordAdjustment({
    required int userId,
    required String kind,
    required String basis,
    required String reason,
    double? amount,
    double? days,
    String? occurredOn,
  }) async {
    final body = await _client.post('/staff-adjustments', {
      'user_id': userId,
      'kind': kind,
      'basis': basis,
      'reason': reason,
      if (amount != null) 'amount': amount,
      if (days != null) 'days': days,
      if (occurredOn != null) 'occurred_on': occurredOn,
    });

    return StaffAdjustment.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// One person's month: the two totals and the entries behind them.
  Future<AdjustmentPeriod> adjustmentsFor(int userId) async {
    final body = await _client.get('/staff-adjustments/period', query: {
      'user_id': '$userId',
    });

    return AdjustmentPeriod.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<void> deleteAdjustment(int id) => _client.delete('/staff-adjustments/$id');

  // --------------------------------------------------------------- payroll

  /// Everyone on the payroll, with what the shop has agreed to pay them.
  Future<List<Employee>> payrollEmployees() async {
    final body = await _client.get('/salaries/employees');

    return ((body['data'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(Employee.fromJson)
        .toList();
  }

  /// The payslips already written, newest period first.
  Future<List<Payslip>> payslips({String? status}) async {
    final body = await _client.get(
      '/salaries${status == null ? '' : '?status=$status'}',
    );

    return _paginated(body).map(Payslip.fromJson).toList();
  }

  /// Writes a payslip for one person for one period.
  ///
  /// Amounts go up in the unit the shop is set to and the server converts;
  /// sending Toman from a Rial shop is how every payslip once got saved ten
  /// times over. The net is the server's arithmetic, not ours.
  ///
  /// [paidOn] set means the money has actually been handed over. Left null
  /// the slip is written and owed, which is the honest state for a payroll
  /// prepared before payday.
  Future<Payslip> recordSalary({
    required int userId,
    required String periodStart,
    required double baseAmount,
    double bonus = 0,
    double deduction = 0,
    String? paidOn,
    int? bankAccountId,
    String? note,
  }) async {
    final body = await _client.post('/salaries', {
      'user_id': userId,
      'period_start': periodStart,
      'base_amount': baseAmount,
      'bonus': bonus,
      'deduction': deduction,
      if (paidOn != null) 'paid_on': paidOn,
      // Null when it came out of the till, which is a real answer. Sent
      // either way: without it the payslip records the cost and moves no
      // account, so the wage is paid and the balance never falls.
      'bank_account_id': bankAccountId,
      if (note != null && note.isNotEmpty) 'note': note,
    });

    return Payslip.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// Marks a slip already written as handed over.
  Future<void> markSalaryPaid(int id) async {
    await _client.patch('/salaries/$id/mark-paid', const {});
  }

  Future<List<ChaneEntry>> myChaneHistory() async {
    final body = await _client.getCached('/chane-entries/my-history');
    return _paginated(body).map(ChaneEntry.fromJson).toList();
  }

  Future<List<ChaneEntry>> pendingChane() async {
    final body = await _client.getCached('/chane-entries/pending');
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

  // ------------------------------- seller: schools, offices, dormitories

  /// What the buyers who run an account owe this seller.
  Future<({List<Map<String, dynamic>> customers, String totalFormatted})>
      myCollections() async {
    final body = await _client.get('/my-collections');
    final data = body['data'] as Map<String, dynamic>;

    return (
      customers: ((data['customers'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => e.map((k, v) => MapEntry('$k', v)))
          .toList(),
      totalFormatted: '${data['total_formatted'] ?? ''}',
    );
  }

  /// Records money a buyer handed back, oldest invoice first.
  ///
  /// [method] is how it arrived: 'cash' stays in the till, 'card' is banked,
  /// because that money really did reach the account.
  /// Queued when offline: the seller is standing at the customer's door
  /// with the money in their hand, and asking them to remember it until
  /// there is signal is how a collection goes unrecorded.
  Future<bool> collectFromCustomer(
    int customerId,
    double amount, {
    String method = 'cash',
  }) async {
    final body = await _client.postOrQueue(
      '/my-collections/$customerId/collect',
      {'amount': amount, 'method': method},
      label: 'وصولی از مشتری',
    );

    return body['queued'] == true;
  }

  /// Asks the admin to confirm the seller has handed their account over.
  /// The account only clears once the admin agrees, so this returns the
  /// request rather than a settled balance.
  Future<SettlementRequest> requestSettlement({
    String? note,
    double? paidCash,
    double? paidCard,
    Map<PaymentType, double>? payments,
    List<int>? saleIds,
    double? amount,
  }) async {
    final body = await _client.post('/settlement-requests', {
      if (note != null && note.isNotEmpty) 'note': note,
      if (paidCash != null) 'paid_cash': paidCash,
      if (paidCard != null) 'paid_card': paidCard,
      // Naming the debts settles only those. Sending none means the whole
      // account, which is what the app did before this existed.
      if (saleIds != null && saleIds.isNotEmpty) 'sale_ids': saleIds,
      // An amount pays the running account down oldest debt first, so
      // it does not have to line up with any particular sale.
      if (amount != null) 'amount': amount,
      // An amount per payment type, so the admin counts what the seller
      // counted out rather than one lump sum.
      if (payments != null && payments.isNotEmpty)
        'payments': [
          for (final entry in payments.entries)
            if (entry.value > 0)
              {'payment_type': entry.key.apiValue, 'amount': entry.value},
        ],
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

  /// The seller's running account: one figure to pay against.
  Future<SellerRunningAccount> sellerAccount() async {
    final body = await _client.get('/settlement-requests/account');

    return SellerRunningAccount.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// The open debts the seller may hand over, one line each, so they can
  /// settle part of the account instead of all of it.
  Future<({List<SettleableLine> lines, String totalFormatted})>
      settleableLines() async {
    final body = await _client.get('/settlement-requests/settleable');
    final data = body['data'] as Map<String, dynamic>;

    return (
      lines: ((data['lines'] as List?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(SettleableLine.fromJson)
          .toList(),
      totalFormatted: '${data['total_formatted'] ?? ''}',
    );
  }

  // ------------------------------------------ admin: seller accounts

  /// What every seller still owes, and who has asked to settle.
  /// What is in the shop's bank accounts, and what they come to together.
  ///
  /// Cached like the other admin reads, so the figure is still there when
  /// the phone cannot reach the server — with the sync card above it
  /// saying plainly that it is not live.
  Future<BankBalances> bankBalances() async {
    final body = await _client.getCached('/bank-accounts');

    return BankBalances.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// How the shop was staffed over a stretch: working days, who was
  /// expected and who actually turned up.
  Future<Map<String, dynamic>> attendanceSummary({String? from, String? to}) async {
    final body = await _client.getCached('/reports/attendance-summary', query: {
      if (from != null) 'from': from,
      if (to != null) 'to': to,
    });

    return body['data'] as Map<String, dynamic>;
  }

  /// What each person is owed for a stretch, and what has been paid.
  Future<Map<String, dynamic>> payrollReport({String? from, String? to}) async {
    final body = await _client.getCached('/reports/payroll', query: {
      if (from != null) 'from': from,
      if (to != null) 'to': to,
    });

    return body['data'] as Map<String, dynamic>;
  }

  /// What the shop owns against what it owes, as of now.
  ///
  /// Cached like the other admin reads, so a phone that cannot reach the
  /// server still shows the last sheet — with the card above it saying
  /// plainly that it is not live.
  Future<BalanceSheet> balanceSheet() async {
    final body = await _client.getCached('/reports/balance-sheet');

    return BalanceSheet.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// Money in against money out, cut into days, weeks or months.
  ///
  /// [granularity] is 'day', 'week' or 'month'; anything else the server
  /// reads as a day.
  Future<FinancialSeries> financialSeries({
    required String from,
    required String to,
    String granularity = 'day',
  }) async {
    final body = await _client.get('/reports/financial-series', query: {
      'from': from,
      'to': to,
      'granularity': granularity,
    });

    return FinancialSeries.fromJson(body['data'] as Map<String, dynamic>);
  }

  /// What has moved through one account, most recent first.
  ///
  /// Not cached: a statement is read to answer a question about a
  /// particular moment, and a stale one would answer it wrongly.
  Future<BankStatement> bankStatement(int accountId, {String? from, String? until}) async {
    final body = await _client.get('/bank-accounts/$accountId/transactions', query: {
      if (from != null) 'from': from,
      if (until != null) 'until': until,
    });

    return BankStatement.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<List<Map<String, dynamic>>> sellerAccounts() async {
    final body = await _client.get('/seller-accounts');
    final data = body['data'] as Map<String, dynamic>;

    return ((data['sellers'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => e.map((k, v) => MapEntry('$k', v)))
        .toList();
  }

  Future<void> confirmSettlement(int requestId, {int? bankAccountId}) =>
      _client.post('/settlement-requests/$requestId/confirm', {
        if (bankAccountId != null) 'bank_account_id': bankAccountId,
      });

  Future<void> rejectSettlement(int requestId, String reason) =>
      _client.post('/settlement-requests/$requestId/reject', {
        'reason': reason,
      });

  /// Settles a seller who handed the money over in person, without having
  /// sent a request from their own app.
  Future<void> settleSeller(int sellerId) =>
      _client.post('/seller-accounts/$sellerId/settle', const {});

  // -------------------------------------- admin: school and office debts

  /// What each school or office still owes, longest-waiting first.
  Future<({List<Map<String, dynamic>> customers, String totalFormatted, int overdueCount})>
      customerDebts() async {
    final body = await _client.get('/customer-debts');
    final data = body['data'] as Map<String, dynamic>;

    return (
      customers: ((data['customers'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => e.map((k, v) => MapEntry('$k', v)))
          .toList(),
      totalFormatted: '${data['total_formatted'] ?? ''}',
      overdueCount: (data['overdue_count'] as num?)?.toInt() ?? 0,
    );
  }

  /// Today's call list: follow-ups that have come due, oldest first.
  Future<List<Map<String, dynamic>>> dueFollowUps() async {
    final body = await _client.get('/follow-ups');
    final data = body['data'] as Map<String, dynamic>;

    return ((data['follow_ups'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => e.map((k, v) => MapEntry('$k', v)))
        .toList();
  }

  Future<bool> recordInteraction(
    int customerId, {
    required String type,
    required String summary,
    String? followUpOn,
  }) async {
    final body = await _client.postOrQueue(
      '/customers/$customerId/interactions',
      {
        'type': type,
        'summary': summary,
        if (followUpOn != null && followUpOn.isNotEmpty)
          'follow_up_on': followUpOn,
      },
      label: 'ثبت تماس با مشتری',
    );

    return body['queued'] == true;
  }

  Future<bool> completeFollowUp(int interactionId) async {
    final body = await _client.postOrQueue(
      '/interactions/$interactionId/complete',
      const {},
      label: 'تکمیل پیگیری',
    );

    return body['queued'] == true;
  }

  Future<void> settleCustomerDebt(int customerId) =>
      _client.post('/customer-debts/$customerId/settle', const {});

  Future<({List<Sale> sales, int count, double total})> todaySales() async {
    final body = await _client.getCached('/sales/today');
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
    final body = await _client.getCached('/work-starts/today');

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
    final body = await _client.getCached('/chane-board');

    return ChaneBoard.fromJson(body['data'] as Map<String, dynamic>);
  }

  // --------------------------------------------------------------- admin

  /// Admin dashboard counters for today.
  Future<Map<String, dynamic>> dashboard() async {
    final body = await _client.getCached('/reports/dashboard');

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

  /// Stock bought into the warehouse.
  ///
  /// Flour is counted in sacks because that is how it arrives; salt and
  /// yeast are weighed, and the server refuses a sack count for them
  /// rather than converting at a figure nobody measures. So the caller
  /// says which it has and this sends that field alone.
  Future<bool> recordStockIntake({
    required String item,
    required double quantity,
    required bool inSacks,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/inventory/movements',
      {
        'item': item,
        'direction': 'in',
        if (inSacks) 'bags': quantity else 'quantity': quantity,
        'reason': 'purchase',
        if (note != null && note.isNotEmpty) 'note': note,
      },
      label: 'ورودی انبار — $quantity ${inSacks ? 'کیسه' : 'کیلو'}',
    );

    return body['queued'] == true;
  }

  /// Flour lent to or borrowed from another bakery.
  /// Flour lent to or borrowed from a neighbouring bakery, counted in
  /// sacks — the weight follows from the sack size on the server.
  Future<bool> recordConsignmentFlour({
    required String partnerName,
    required String direction,
    required double bags,
    String? note,
  }) async {
    final body = await _client.postOrQueue(
      '/consignment-flour',
      {
        'partner_name': partnerName,
        'direction': direction,
        'bags': bags,
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
    final body = await _client.getCached('/inventory');

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
    final body = await _client.getCached('/bakery');
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

  // ------------------------------------------------- diesel quota

  /// This month's diesel quota and the tankers drawn against it.
  ///
  /// The allocation is null when no quota has been registered — the shop
  /// is told so rather than shown a guessed figure.
  Future<({DieselQuota? quota, List<DieselDelivery> deliveries})>
      dieselQuota() async {
    final body = await _client.get('/diesel/quota');
    final data = body['data'] as Map<String, dynamic>;
    final allocation = data['allocation'] as Map<String, dynamic>?;

    return (
      quota: allocation == null ? null : DieselQuota.fromJson(allocation),
      deliveries: ((data['deliveries'] as List?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(DieselDelivery.fromJson)
          .toList(),
    );
  }

  /// Records a tanker. The warning comes back set when it took the month
  /// past its quota, so it can be said while the driver is still there.
  Future<({DieselQuota? quota, String? warning})> recordDieselDelivery({
    required double litres,
    double? amount,
    String? docketNumber,
    String? note,
  }) async {
    final body = await _client.post('/diesel/deliveries', {
      'litres': litres,
      if (amount != null) 'amount': amount,
      if (docketNumber != null && docketNumber.isNotEmpty)
        'docket_number': docketNumber,
      if (note != null && note.isNotEmpty) 'note': note,
    });

    final data = body['data'] as Map<String, dynamic>;
    final allocation = data['allocation'] as Map<String, dynamic>?;

    return (
      quota: allocation == null ? null : DieselQuota.fromJson(allocation),
      warning: data['warning'] as String?,
    );
  }

  Future<void> removeDieselDelivery(int id) =>
      _client.delete('/diesel/deliveries/$id');

  /// Changes what a sack earns this month, and with it the default the
  /// next month starts from.
  Future<DieselQuota?> setDieselRate({
    double? litresPerBag,
    double? totalLitres,
  }) async {
    final body = await _client.patch('/diesel/quota', {
      if (litresPerBag != null) 'litres_per_bag': litresPerBag,
      if (totalLitres != null) 'total_litres': totalLitres,
    });

    final allocation =
        (body['data'] as Map<String, dynamic>)['allocation'] as Map<String, dynamic>?;

    return allocation == null ? null : DieselQuota.fromJson(allocation);
  }

  /// Registers the month's flour quota. The diesel quota follows it, so
  /// this is what a new month starts from.
  Future<void> setFlourQuota({
    required double totalBags,
    double? carryoverBags,
    String? note,
  }) =>
      _client.post('/flour-allocations', {
        'total_bags': totalBags,
        if (carryoverBags != null) 'carryover_bags': carryoverBags,
        if (note != null && note.isNotEmpty) 'note': note,
      });

  // --------------------------------------------- advances on pay

  /// What this person has drawn against their pay, and what next month
  /// will be short by.
  Future<({List<StaffAdvance> advances, String summary, double outstanding})>
      myAdvances() async {
    final body = await _client.get('/staff-advances/mine');
    final data = body['data'] as Map<String, dynamic>;

    return (
      advances: ((data['advances'] as List?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(StaffAdvance.fromJson)
          .toList(),
      summary: data['summary'] as String? ?? '',
      outstanding: switch (data['outstanding']) {
        final num n => n.toDouble(),
        _ => 0.0,
      },
    );
  }

  /// What this person is owed, in one call, for the card on their home
  /// screen — their pay was visible to everyone but them.
  Future<PaySummary> myPaySummary() async {
    final body = await _client.get('/salaries/my-summary');

    return PaySummary.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<({List<AdvanceRequest> requests, bool hasPending})>
      myAdvanceRequests() async {
    final body = await _client.get('/advance-requests/mine');
    final data = body['data'] as Map<String, dynamic>;

    return (
      requests: ((data['requests'] as List?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(AdvanceRequest.fromJson)
          .toList(),
      hasPending: data['has_pending'] as bool? ?? false,
    );
  }

  // ------------------------------------------- asking to be paid

  /// Asks to be paid for the month. No amount is sent: the wage is what
  /// was agreed, and what this says is «I have not had it».
  Future<SalaryRequest> requestSalary({String? note}) async {
    final body = await _client.post('/salary-requests', {
      if (note != null && note.isNotEmpty) 'note': note,
    });

    return SalaryRequest.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<List<SalaryRequest>> mySalaryRequests() async {
    final body = await _client.get('/salary-requests/mine');

    return ((body['data'] as List?) ?? const [])
        .cast<Map<String, dynamic>>()
        .map(SalaryRequest.fromJson)
        .toList();
  }

  Future<void> withdrawSalaryRequest(int id) =>
      _client.delete('/salary-requests/$id');

  /// Everything waiting, for whoever pays the wages. There is no approve
  /// call to go with the reject: paying the person through the pay sheet
  /// is what approval means, and that is where the figures are on screen.
  Future<List<SalaryRequest>> pendingSalaryRequests() async {
    final body = await _client.get('/salary-requests?status=pending');

    return _paginated(body).map(SalaryRequest.fromJson).toList();
  }

  Future<void> rejectSalaryRequest(int id, {required String note}) =>
      _client.patch('/salary-requests/$id/reject', {'decision_note': note});

  Future<AdvanceRequest> requestAdvance({
    required double amount,
    String? reason,
  }) async {
    final body = await _client.post('/advance-requests', {
      'amount': amount,
      if (reason != null && reason.isNotEmpty) 'reason': reason,
    });

    return AdvanceRequest.fromJson(body['data'] as Map<String, dynamic>);
  }

  Future<void> withdrawAdvanceRequest(int id) =>
      _client.delete('/advance-requests/$id');

  /// The requests waiting on an answer, for whoever gives it.
  Future<List<AdvanceRequest>> pendingAdvanceRequests() async {
    final body = await _client.get('/advance-requests');

    return _paginated(body).map(AdvanceRequest.fromJson).toList();
  }

  Future<void> approveAdvanceRequest(int id, {String? note}) =>
      _client.patch('/advance-requests/$id/approve', {
        if (note != null && note.isNotEmpty) 'note': note,
      });

  /// Turning one down needs a reason: a bare "no" to someone asking for
  /// money early is worse than no answer at all.
  Future<void> rejectAdvanceRequest(int id, {required String note}) =>
      _client.patch('/advance-requests/$id/reject', {'note': note});

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
