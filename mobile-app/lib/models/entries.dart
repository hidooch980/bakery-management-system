class DoughEntry {
  const DoughEntry({
    required this.id,
    required this.bagCount,
    required this.status,
    this.note,
    this.userName,
    this.createdAt,
  });

  final int id;
  final int bagCount;
  final String status;
  final String? note;
  final String? userName;
  final DateTime? createdAt;

  bool get isPending => status == 'pending';

  factory DoughEntry.fromJson(Map<String, dynamic> json) => DoughEntry(
        id: json['id'] as int,
        bagCount: (json['bag_count'] as num).toInt(),
        status: json['status'] as String? ?? 'pending',
        note: json['note'] as String?,
        userName: (json['user'] as Map<String, dynamic>?)?['name'] as String?,
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
      );
}

class ChaneEntry {
  const ChaneEntry({
    required this.id,
    required this.doughEntryId,
    required this.chaneCount,
    required this.normalWeightKg,
    required this.naninoWeightKg,
    required this.sprayFlourKg,
    required this.status,
    this.userName,
    this.createdAt,
  });

  final int id;
  final int doughEntryId;
  final int chaneCount;
  final double normalWeightKg;
  final double naninoWeightKg;
  final double sprayFlourKg;
  final String status;
  final String? userName;
  final DateTime? createdAt;

  /// The weight that counts for sales, stock and reporting.
  ///
  /// Only the normal chane is real output; nanino is recorded for comparison
  /// and is deliberately excluded from every calculation.
  double get weightKg => normalWeightKg;

  /// Both weights added together — for the comparison view only, never for
  /// sales or stock figures.
  double get displayTotalWeightKg => normalWeightKg + naninoWeightKg;

  bool get isPending => status == 'pending';

  factory ChaneEntry.fromJson(Map<String, dynamic> json) => ChaneEntry(
        id: json['id'] as int,
        doughEntryId: (json['dough_entry_id'] as num).toInt(),
        chaneCount: (json['chane_count'] as num).toInt(),
        normalWeightKg: double.tryParse('${json['normal_weight_kg']}') ?? 0,
        naninoWeightKg: double.tryParse('${json['nanino_weight_kg']}') ?? 0,
        sprayFlourKg: double.tryParse('${json['spray_flour_kg']}') ?? 0,
        status: json['status'] as String? ?? 'pending',
        userName: (json['user'] as Map<String, dynamic>?)?['name'] as String?,
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
      );
}

/// Payment methods accepted at the counter.
enum PaymentType {
  cash('cash', 'نقد'),
  card('card', 'کارتخوان'),
  credit('credit', 'نسیه'),
  home('home', 'منزل'),
  schools('schools', 'مدارس'),
  charity('charity', 'خیرات و کمک'),
  shortfall('shortfall', 'کسری نان'),
  other('other', 'سایر');

  const PaymentType(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static PaymentType fromApi(String? value) =>
      PaymentType.values.firstWhere(
        (t) => t.apiValue == value,
        orElse: () => PaymentType.other,
      );

  /// What the counter offers.
  ///
  /// A shortfall is worked out from the batch — the chane count less what
  /// was accounted for — and lands on the seller's own account without
  /// anyone naming it, so asking them to pick it invited a second, hand-made
  /// figure beside the derived one. `other` named nothing at all. Neither
  /// was chosen once in this shop's history.
  ///
  /// **`credit` came off the list on 1405/06/03, at the owner's word.**
  /// Bread let out on trust is a debt the shop then has to chase, and he
  /// would rather it were not offered at the counter at all.
  ///
  /// `schools` stays, and deliberately: it is also a debt type, but it is
  /// a standing arrangement with a named institution rather than a
  /// judgement a seller makes at the door.
  ///
  /// All three stay in the enum. An older sale still has to render as
  /// words — this shop has 179 loaves of credit in the current period
  /// alone, and 500,000 Rial of it uncollected — and [fromApi] falls back
  /// to `other` for a value this build has never heard of.
  static List<PaymentType> get choices => const [
        PaymentType.cash,
        PaymentType.card,
        PaymentType.home,
        PaymentType.schools,
        PaymentType.charity,
      ];

  /// Types the shop must know the buyer for before it can record the sale.
  bool get needsCustomer =>
      this == PaymentType.credit || this == PaymentType.schools;

  /// Bread that leaves with no money behind it — donated, or taken home.
  /// The sheet asks for no amount, and the seller is not left owing the
  /// price of what was given away.
  bool get isGiveaway =>
      this == PaymentType.charity || this == PaymentType.home;

  /// Loaves the seller cannot account for. No money is expected either,
  /// but unlike a giveaway this does land on their account — the bread
  /// left and nothing came back for it, and naming it at the counter beats
  /// meeting it as a figure at the end of the month.
  bool get isShortfall => this == PaymentType.shortfall;

  /// Neither of the above expects an amount to be typed.
  bool get expectsNoAmount => isGiveaway || isShortfall;
}

/// One way a batch was paid for: how many loaves went out under this
/// payment type, and the money taken for them.
/// A member of staff, by name, for the «چه کسی برد» picker.
///
/// Deliberately not the full User model: this list is read by a seller,
/// who has no business seeing wages or roles, and the server sends only
/// the two fields the picker needs.
class StaffName {
  const StaffName({required this.id, required this.name});

  final int id;
  final String name;

  factory StaffName.fromJson(Map<String, dynamic> json) => StaffName(
        id: json['id'] as int,
        name: (json['name'] ?? '') as String,
      );
}

class SalePaymentLine {
  const SalePaymentLine({
    required this.paymentType,
    required this.breadCount,
    this.amount,
    this.customerId,
    this.consumedByUserId,
  });

  final PaymentType paymentType;
  final int breadCount;
  final double? amount;
  final int? customerId;

  /// Who took the bread home, on a «منزل» line. The seller names them and
  /// the price comes off that person's payslip at month end — it is never
  /// charged to the seller, who only handed it over.
  final int? consumedByUserId;

  Map<String, dynamic> toJson() => {
        'payment_type': paymentType.apiValue,
        'bread_count': breadCount,
        if (amount != null) 'amount': amount,
        if (customerId != null) 'customer_id': customerId,
        if (consumedByUserId != null) 'consumed_by_user_id': consumedByUserId,
      };
}

class Sale {
  const Sale({
    required this.id,
    required this.chaneEntryId,
    required this.paymentType,
    this.amount,
    this.note,
    this.createdAt,
  });

  final int id;
  final int chaneEntryId;
  final PaymentType paymentType;
  final double? amount;
  final String? note;
  final DateTime? createdAt;

  factory Sale.fromJson(Map<String, dynamic> json) => Sale(
        id: json['id'] as int,
        chaneEntryId: (json['chane_entry_id'] as num).toInt(),
        paymentType: PaymentType.fromApi(json['payment_type'] as String?),
        amount: json['amount'] == null
            ? null
            : double.tryParse('${json['amount']}'),
        note: json['note'] as String?,
        createdAt: DateTime.tryParse(json['created_at'] as String? ?? ''),
      );
}

class AttendanceRecord {
  const AttendanceRecord({
    required this.id,
    required this.date,
    required this.checkedInAt,
  });

  final int id;
  final DateTime date;
  final DateTime checkedInAt;

  factory AttendanceRecord.fromJson(Map<String, dynamic> json) =>
      AttendanceRecord(
        id: json['id'] as int,
        date: DateTime.parse(json['date'] as String),
        checkedInAt: DateTime.parse(json['checked_in_at'] as String),
      );
}

/// One person on the floor, and whether they are in yet.
///
/// Used by whoever is ticking the others in — the bakers work with flour on
/// their hands and their phones in a locker.
class StaffAttendance {
  const StaffAttendance({
    required this.id,
    required this.name,
    required this.checkedIn,
    this.role,
    this.checkedInAt,
    this.recordedByAnother = false,
  });

  final int id;
  final String name;
  final bool checkedIn;
  final String? role;

  /// Wall-clock time, already formatted by the server.
  final String? checkedInAt;

  /// Someone entered this for them rather than them entering it. Shown so
  /// the seller can see the tick is theirs, not evidence the person came
  /// to the phone.
  final bool recordedByAnother;

  factory StaffAttendance.fromJson(Map<String, dynamic> json) =>
      StaffAttendance(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: '${json['name'] ?? ''}',
        checkedIn: json['checked_in'] as bool? ?? false,
        role: json['role'] as String?,
        checkedInAt: json['checked_in_at'] as String?,
        recordedByAnother: json['recorded_by_another'] as bool? ?? false,
      );
}
