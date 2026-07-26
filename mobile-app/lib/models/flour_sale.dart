import 'entries.dart';

/// Whether flour is being sold loose or by the sack.
enum FlourUnit {
  kg('kg', 'کیلوگرم'),
  bag('bag', 'کیسه');

  const FlourUnit(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static FlourUnit fromApi(String? value) => FlourUnit.values.firstWhere(
        (unit) => unit.apiValue == value,
        orElse: () => FlourUnit.kg,
      );
}

/// One flour sale, as the API reports it back.
class FlourSale {
  const FlourSale({
    required this.id,
    required this.unit,
    required this.quantity,
    required this.weightKg,
    required this.amount,
    required this.paymentType,
    required this.quantityLabel,
    required this.amountFormatted,
    this.customerName,
    this.note,
  });

  final int id;
  final FlourUnit unit;
  final double quantity;
  final double weightKg;
  final double amount;
  final PaymentType paymentType;
  final String quantityLabel;
  final String amountFormatted;
  final String? customerName;
  final String? note;

  factory FlourSale.fromJson(Map<String, dynamic> json) {
    final customer = json['customer'];

    return FlourSale(
      id: _int(json['id']),
      unit: FlourUnit.fromApi(json['unit'] as String?),
      quantity: _double(json['quantity']),
      weightKg: _double(json['weight_kg']),
      amount: _double(json['amount']),
      paymentType: PaymentType.fromApi(json['payment_type'] as String?),
      quantityLabel: json['quantity_label'] as String? ?? '',
      amountFormatted: json['amount_formatted'] as String? ?? '',
      customerName:
          customer is Map ? customer['name'] as String? : null,
      note: json['note'] as String?,
    );
  }

  static int _int(dynamic value) =>
      value is num ? value.toInt() : int.tryParse('$value') ?? 0;

  static double _double(dynamic value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
}

/// What the counter needs before writing a sale: the going rates and how
/// much flour is actually left in the warehouse.
class FlourSaleOptions {
  const FlourSaleOptions({
    required this.bagWeightKg,
    required this.availableKg,
    required this.availableBags,
    required this.pricePerKg,
    required this.pricePerBag,
    required this.currencyLabel,
  });

  final double bagWeightKg;
  final double availableKg;
  final double availableBags;
  final double pricePerKg;
  final double pricePerBag;
  final String currencyLabel;

  /// The going rate for whichever unit is selected.
  double priceFor(FlourUnit unit) =>
      unit == FlourUnit.bag ? pricePerBag : pricePerKg;

  /// How much can still be sold, in the selected unit.
  double availableIn(FlourUnit unit) =>
      unit == FlourUnit.bag ? availableBags : availableKg;

  factory FlourSaleOptions.fromJson(Map<String, dynamic> json) {
    final units = (json['units'] as List?)?.cast<Map<String, dynamic>>() ?? [];

    double priceOf(String key) {
      for (final unit in units) {
        if (unit['key'] == key) return FlourSale._double(unit['unit_price']);
      }
      return 0;
    }

    return FlourSaleOptions(
      bagWeightKg: FlourSale._double(json['bag_weight_kg']),
      availableKg: FlourSale._double(json['available_kg']),
      availableBags: FlourSale._double(json['available_bags']),
      pricePerKg: priceOf('kg'),
      pricePerBag: priceOf('bag'),
      currencyLabel: json['currency_label'] as String? ?? '',
    );
  }
}
