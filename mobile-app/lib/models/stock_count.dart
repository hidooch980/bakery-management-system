/// شمارش آنچه روی قفسه بود، در برابر آنچه دفتر انتظار داشت.
///
/// قرینهٔ [CashCount] و به همان دلیل: اختلاف را سرور حساب کرده و با کلمه
/// هم گفته است — «کسری»، «اضافه»، «می‌خواند». اپ دوباره حسابش نمی‌کند،
/// چون علامتی که صبح شلوغ یک‌نگاهی خوانده شود همان چیزی است که نباید
/// اشتباه خوانده شود، و دو جا که تصمیم بگیرند منفی یعنی چه، همین‌طور با
/// هم اختلاف پیدا می‌کنند.
class StockCount {
  const StockCount({
    required this.id,
    required this.countedLabel,
    required this.expectedLabel,
    required this.differenceLabel,
    required this.differenceAmount,
    required this.isExact,
    required this.adjusted,
    required this.countedAt,
    this.countedBy,
    this.note,
  });

  final int id;
  final String countedLabel;
  final String expectedLabel;

  /// همیشه اندازهٔ اختلاف، هیچ‌وقت علامتش — جهت در [differenceLabel] است.
  final String differenceAmount;
  final String differenceLabel;

  final bool isExact;

  /// آیا دفتر با این شمارش یکی شد.
  final bool adjusted;

  final String countedAt;
  final String? countedBy;
  final String? note;

  static String _text(dynamic value) => value is String ? value : '';

  static bool _bool(dynamic value) =>
      value == true || value == 1 || value == '1';

  /// «۱۱۳ کیسه» اگر کالا کیسه‌ای باشد، وگرنه «۴٬۵۲۰ کیلوگرم».
  static String _amount(dynamic value) {
    if (value is! Map) return '';

    final bags = value['bags'];

    if (bags is num) return '${_trim(bags)} کیسه';

    final base = value['base'];

    return base is num ? '${_trim(base)} ${_text(value['base_unit'])}' : '';
  }

  /// عددی بدون صفرِ بی‌فایدهٔ آخر: «۱۱۳»، نه «۱۱۳٫۰۰».
  static String _trim(num value) =>
      value.toStringAsFixed(value == value.roundToDouble() ? 0 : 2);

  factory StockCount.fromJson(Map<String, dynamic> json) => StockCount(
        id: (json['id'] as num?)?.toInt() ?? 0,
        countedLabel: _amount(json['counted']),
        expectedLabel: _amount(json['expected']),
        differenceAmount: _amount(json['difference']),
        differenceLabel: _text(json['difference_label']),
        isExact: _bool(json['is_exact']),
        adjusted: _bool(json['adjusted']),
        countedAt: _text(json['counted_at']),
        countedBy:
            json['counted_by'] is String ? json['counted_by'] as String : null,
        note: json['note'] is String ? json['note'] as String : null,
      );
}

/// انبار آن‌طور که دفتر می‌بیند، به‌علاوهٔ هر شمارشی که رویش شده.
class StockCountBook {
  const StockCountBook({
    required this.itemName,
    required this.unitLabel,
    required this.expectedLabel,
    required this.expectedValue,
    required this.counts,
    this.lastCountedAt,
    this.daysSinceCount,
  });

  final String itemName;

  /// واحدی که صفحه می‌پرسد و نشان می‌دهد: «کیسه» یا «کیلوگرم».
  final String unitLabel;

  final String expectedLabel;

  /// همان عدد، برای پر کردن اولیهٔ کادر — تا کسی که فقط تأیید می‌کند
  /// مجبور نباشد دوباره تایپش کند.
  final double expectedValue;

  final List<StockCount> counts;
  final String? lastCountedAt;
  final int? daysSinceCount;

  bool get neverCounted => lastCountedAt == null;

  factory StockCountBook.fromJson(Map<String, dynamic> json) {
    final item = json['item'] is Map ? json['item'] as Map : const {};
    final expected = json['expected'] is Map ? json['expected'] as Map : const {};
    final bags = expected['bags'];
    final isBagged = bags is num;

    return StockCountBook(
      itemName: item['name'] is String ? item['name'] as String : '',
      unitLabel: isBagged
          ? 'کیسه'
          : (expected['base_unit'] is String
              ? expected['base_unit'] as String
              : ''),
      expectedLabel: StockCount._amount(json['expected']),
      expectedValue: isBagged
          ? bags.toDouble()
          : (expected['base'] is num ? (expected['base'] as num).toDouble() : 0),
      lastCountedAt: json['last_counted_at'] is String
          ? json['last_counted_at'] as String
          : null,
      daysSinceCount: (json['days_since_count'] as num?)?.toInt(),
      counts: [
        for (final row in (json['counts'] as List?) ?? const [])
          if (row is Map<String, dynamic>) StockCount.fromJson(row),
      ],
    );
  }
}
