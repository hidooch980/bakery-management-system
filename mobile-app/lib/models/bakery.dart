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
  });

  final String name;
  final String? address;
  final String? phone;
  final String? description;

  /// Weight of a single normal chane, in kilograms.
  final double? normalChaneWeightKg;

  /// Weight of a single nanino-system chane, in kilograms.
  final double? naninoChaneWeightKg;

  /// Price of one loaf, used to suggest a sale amount.
  final double? breadPrice;

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
      );

  /// The API returns decimals as strings, so parse defensively.
  static double? _toDouble(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toDouble();

    return double.tryParse('$value');
  }
}
