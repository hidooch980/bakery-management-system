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
  other('other', 'سایر');

  const PaymentType(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static PaymentType fromApi(String? value) =>
      PaymentType.values.firstWhere(
        (t) => t.apiValue == value,
        orElse: () => PaymentType.other,
      );

  /// Types the shop must know the buyer for before it can record the sale.
  bool get needsCustomer =>
      this == PaymentType.credit || this == PaymentType.schools;

  /// Bread given away — to a mosque, a religious school or anyone in need.
  /// No money is expected, so the sheet asks for no amount and the seller
  /// is not left owing the price of what was donated.
  bool get isGiveaway => this == PaymentType.charity;
}

/// One way a batch was paid for: how many loaves went out under this
/// payment type, and the money taken for them.
class SalePaymentLine {
  const SalePaymentLine({
    required this.paymentType,
    required this.breadCount,
    this.amount,
    this.customerId,
  });

  final PaymentType paymentType;
  final int breadCount;
  final double? amount;
  final int? customerId;

  Map<String, dynamic> toJson() => {
        'payment_type': paymentType.apiValue,
        'bread_count': breadCount,
        if (amount != null) 'amount': amount,
        if (customerId != null) 'customer_id': customerId,
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
