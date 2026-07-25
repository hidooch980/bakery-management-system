import 'package:intl/intl.dart';
import 'package:shamsi_date/shamsi_date.dart';

/// Every date shown in the app goes through here, so the UI is Jalali
/// end-to-end even though the API stores Gregorian timestamps.
class JalaliFormat {
  const JalaliFormat._();

  static const _monthNames = [
    'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
    'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
  ];

  static const _weekDayNames = [
    'شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه',
  ];

  /// ۱۴۰۵/۰۵/۰۳
  static String date(DateTime? value) {
    if (value == null) return '—';

    final j = Jalali.fromDateTime(value.toLocal());

    return '${j.year}/${_two(j.month)}/${_two(j.day)}';
  }

  /// ۱۴۰۵/۰۵/۰۳ — ۰۸:۳۰
  static String dateTime(DateTime? value) {
    if (value == null) return '—';

    return '${date(value)} — ${time(value)}';
  }

  /// ۰۸:۳۰
  static String time(DateTime? value) {
    if (value == null) return '—';

    final local = value.toLocal();

    return '${_two(local.hour)}:${_two(local.minute)}';
  }

  /// ۰۵/۰۳ — compact, for chart axes and list subtitles.
  static String shortDate(DateTime? value) {
    if (value == null) return '—';

    final j = Jalali.fromDateTime(value.toLocal());

    return '${_two(j.month)}/${_two(j.day)}';
  }

  /// شنبه ۳ مرداد ۱۴۰۵
  static String longDate(DateTime? value) {
    if (value == null) return '—';

    final j = Jalali.fromDateTime(value.toLocal());

    return '${_weekDayNames[j.weekDay - 1]} ${j.day} ${_monthNames[j.month - 1]} ${j.year}';
  }

  /// مرداد ۱۴۰۵
  static String monthLabel(DateTime? value) {
    if (value == null) return '—';

    final j = Jalali.fromDateTime(value.toLocal());

    return '${_monthNames[j.month - 1]} ${j.year}';
  }

  /// Turns "1405/05/03" into a DateTime, for sending dates back to the API.
  static DateTime? parse(String? jalaliDate) {
    if (jalaliDate == null || jalaliDate.trim().isEmpty) return null;

    final parts = _toLatinDigits(jalaliDate.trim())
        .replaceAll('-', '/')
        .replaceAll('.', '/')
        .split('/');

    if (parts.length != 3) return null;

    final year = int.tryParse(parts[0]);
    final month = int.tryParse(parts[1]);
    final day = int.tryParse(parts[2]);

    if (year == null || month == null || day == null) return null;
    if (month < 1 || month > 12 || day < 1 || day > 31) return null;

    try {
      return Jalali(year, month, day).toDateTime();
    } catch (_) {
      // Rejects impossible dates such as 1405/12/31 in a common year.
      return null;
    }
  }

  /// The API accepts Jalali strings for date filters and entry dates.
  static String toApi(DateTime value) => date(value);

  static String _two(int n) => n.toString().padLeft(2, '0');

  static String _toLatinDigits(String value) {
    const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    var result = value;
    for (var i = 0; i < persian.length; i++) {
      result = result.replaceAll(persian[i], '$i');
    }

    return result;
  }
}

/// Currency unit the bakery displays amounts in. Amounts are always stored
/// and sent to the API in Toman.
enum Currency {
  toman('toman', 'تومان', 1),
  rial('rial', 'ریال', 10);

  const Currency(this.apiValue, this.label, this.multiplier);

  final String apiValue;
  final String label;

  /// How many display units one stored Toman is worth.
  final int multiplier;

  static Currency fromApi(String? value) => Currency.values.firstWhere(
        (c) => c.apiValue == value,
        orElse: () => Currency.toman,
      );
}

class MoneyFormat {
  const MoneyFormat._();

  static final _grouped = NumberFormat.decimalPattern();

  /// "۱٬۲۶۰٬۰۰۰ تومان" — grouped, with the configured unit appended.
  static String format(num? toman, {Currency currency = Currency.toman}) {
    final amount = (toman ?? 0) * currency.multiplier;

    return '${_grouped.format(amount)} ${currency.label}';
  }

  /// Grouped digits with no unit, for use inside text fields.
  static String plain(num? toman, {Currency currency = Currency.toman}) {
    return _grouped.format((toman ?? 0) * currency.multiplier);
  }

  /// Converts a figure the user typed in the display unit back to Toman.
  static double toToman(num amount, {Currency currency = Currency.toman}) {
    return amount / currency.multiplier;
  }
}
