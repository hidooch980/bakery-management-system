import '../utils/formatters.dart';

/// Shop profile plus the reference values used to pre-fill entry forms.
class Bakery {
  const Bakery({
    required this.name,
    this.address,
    this.phone,
    this.description,
    this.normalChaneWeightKg,
    this.naninoChaneWeightKg,
    this.breadPrice,
    this.currency = Currency.toman,
  });

  final String name;
  final String? address;
  final String? phone;
  final String? description;

  /// Weight of a single normal chane, in kilograms.
  final double? normalChaneWeightKg;

  /// Weight of a single nanino-system chane, in kilograms.
  final double? naninoChaneWeightKg;

  /// Price of one loaf, in Toman, used to suggest a sale amount.
  final double? breadPrice;

  /// Unit amounts are displayed in. Values stay stored in Toman.
  final Currency currency;

  bool get hasChaneWeights =>
      (normalChaneWeightKg ?? 0) > 0 || (naninoChaneWeightKg ?? 0) > 0;

  bool get hasBreadPrice => (breadPrice ?? 0) > 0;

  factory Bakery.fromJson(Map<String, dynamic> json) => Bakery(
        name: json['name'] as String? ?? '',
        address: json['address'] as String?,
        phone: json['phone'] as String?,
        description: json['description'] as String?,
        normalChaneWeightKg: _toDouble(json['normal_chane_weight_kg']),
        naninoChaneWeightKg: _toDouble(json['nanino_chane_weight_kg']),
        breadPrice: _toDouble(json['bread_price']),
        currency: Currency.fromApi(json['currency'] as String?),
      );

  /// Formats a stored Toman amount in this bakery's display unit.
  String money(num? toman) => MoneyFormat.format(toman, currency: currency);

  /// The API returns decimals as strings, so parse defensively.
  static double? _toDouble(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();

    return double.tryParse('$value');
  }
}
