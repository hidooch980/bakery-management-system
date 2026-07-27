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
    this.chanePerTray,
    this.normalChanePerBag,
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

  /// Chane a full tray holds. Chane is counted out a tray at a time, so
  /// this seeds each new tray; the last one is usually trimmed by hand.
  final int? chanePerTray;

  /// Unit amounts are displayed in. Values stay stored in Toman.
  final Currency currency;

  bool get hasChaneWeights =>
      (normalChaneWeightKg ?? 0) > 0 || (naninoChaneWeightKg ?? 0) > 0;

  bool get hasBreadPrice => (breadPrice ?? 0) > 0;

  /// Falls back to one so a shop that never set a tray size still counts
  /// up from something rather than adding empty trays.
  int get trayStep => (chanePerTray ?? 0) > 0 ? chanePerTray! : 1;

  /// Chane one bag of flour should yield, worked out by the server from
  /// the shop's dough formula so both sides agree on the figure.
  final int? normalChanePerBag;

  /// Chane [bags] of flour should yield, or null when the formula is
  /// incomplete — a guess here would read as a real target.
  int? expectedChaneFor(int bags) =>
      (normalChanePerBag ?? 0) > 0 ? normalChanePerBag! * bags : null;

  factory Bakery.fromJson(Map<String, dynamic> json) => Bakery(
        name: json['name'] as String? ?? '',
        address: json['address'] as String?,
        phone: json['phone'] as String?,
        description: json['description'] as String?,
        normalChaneWeightKg: _toDouble(json['normal_chane_weight_kg']),
        naninoChaneWeightKg: _toDouble(json['nanino_chane_weight_kg']),
        breadPrice: _toDouble(json['bread_price']),
        chanePerTray: _toInt(json['chane_per_tray']),
        normalChanePerBag: _toInt(
          ((json['formula'] as Map<String, dynamic>?)?['per_bag']
              as Map<String, dynamic>?)?['normal_chane_count'],
        ),
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

  static int? _toInt(dynamic value) {
    if (value == null) return null;
    if (value is num) return value.toInt();

    return int.tryParse('$value');
  }
}
