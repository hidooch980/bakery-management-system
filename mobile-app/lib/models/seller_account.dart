/// What a seller is answerable for until it clears: cash they are holding,
/// a gap between money taken and bread sold, bread nobody paid for, and
/// credit they handed out.
///
/// Read-only in the app. Settling is the admin's, since a seller clearing
/// their own debt would defeat the point of recording it.
class SellerAccount {
  const SellerAccount({
    required this.cash,
    required this.cashFormatted,
    required this.difference,
    required this.differenceFormatted,
    required this.shortfall,
    required this.shortfallFormatted,
    required this.shortfallCount,
    required this.credit,
    required this.creditFormatted,
    required this.total,
    required this.totalFormatted,
    required this.entries,
    this.creditSales = const [],
  });

  final double cash;
  final String cashFormatted;

  /// Negative means less money was taken than the bread was worth.
  final double difference;
  final String differenceFormatted;

  final double shortfall;
  final String shortfallFormatted;
  final int shortfallCount;

  final double credit;
  final String creditFormatted;

  final double total;
  final String totalFormatted;

  final int entries;
  final List<SellerCreditSale> creditSales;

  bool get isClear => total == 0;

  bool get hasDifference => difference != 0;

  bool get hasShortfall => shortfallCount > 0;

  bool get hasCredit => credit > 0;

  factory SellerAccount.fromJson(Map<String, dynamic> json) => SellerAccount(
        cash: _double(json['cash']),
        cashFormatted: '${json['cash_formatted'] ?? ''}',
        difference: _double(json['difference']),
        differenceFormatted: '${json['difference_formatted'] ?? ''}',
        shortfall: _double(json['shortfall']),
        shortfallFormatted: '${json['shortfall_formatted'] ?? ''}',
        shortfallCount: _int(json['shortfall_count']),
        credit: _double(json['credit']),
        creditFormatted: '${json['credit_formatted'] ?? ''}',
        total: _double(json['total']),
        totalFormatted: '${json['total_formatted'] ?? ''}',
        entries: _int(json['entries']),
        creditSales: ((json['credit_sales'] as List?) ?? const [])
            .whereType<Map>()
            .map((e) => SellerCreditSale.fromJson(
                e.map((k, v) => MapEntry('$k', v))))
            .toList(),
      );

  static double _double(dynamic value) {
    if (value is num) return value.toDouble();

    return double.tryParse('$value') ?? 0;
  }

  static int _int(dynamic value) {
    if (value is num) return value.toInt();

    return int.tryParse('$value') ?? 0;
  }
}

/// One credit sale still to be collected from its customer.
class SellerCreditSale {
  const SellerCreditSale({
    required this.id,
    required this.breadCount,
    required this.amountFormatted,
    this.customer,
    this.dateDisplay,
  });

  final int id;
  final int breadCount;
  final String amountFormatted;
  final String? customer;
  final String? dateDisplay;

  factory SellerCreditSale.fromJson(Map<String, dynamic> json) =>
      SellerCreditSale(
        id: SellerAccount._int(json['id']),
        breadCount: SellerAccount._int(json['bread_count']),
        amountFormatted: '${json['amount_formatted'] ?? ''}',
        customer: json['customer'] as String?,
        dateDisplay: json['date_display'] as String?,
      );
}
