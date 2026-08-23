import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:shamsi_date/shamsi_date.dart';

/// Persian and Arabic digits rewritten as Latin ones.
///
/// Anything that has to be compared, parsed, or sent has to go through
/// here first. A string that is half Persian and half Latin still parses —
/// the server is forgiving — but it will never compare equal to anything,
/// and comparing is most of what dates get used for.
String latinDigits(String value) {
  const persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  const arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

  var result = value;

  for (var i = 0; i < persian.length; i++) {
    result = result.replaceAll(persian[i], '$i').replaceAll(arabic[i], '$i');
  }

  return result;
}

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

  static String _toLatinDigits(String value) => latinDigits(value);
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

  static final _grouped = NumberFormat.decimalPattern('en');

  /// The shop writes money the way its ledgers do — 100/000/000, not
  /// 100,000,000. A comma reads as a decimal point to anyone used to those
  /// books, which is the wrong thing to be unsure about on a sum of money.
  /// Persian comma, matching Money::GROUP_SEPARATOR on the server. The
  /// phone and the panel must group a figure the same way or the same
  /// wage reads as two different numbers on two screens.
  static const groupSeparator = '،';

  static String _group(num value) =>
      _grouped.format(value).replaceAll(',', groupSeparator);

  /// "100/000/000 ریال" — grouped, with the configured unit appended.
  static String format(num? toman, {Currency currency = Currency.toman}) {
    final amount = (toman ?? 0) * currency.multiplier;

    return '${_group(amount)} ${currency.label}';
  }

  /// Grouped digits with no unit, for use inside text fields.
  static String plain(num? toman, {Currency currency = Currency.toman}) {
    return _group((toman ?? 0) * currency.multiplier);
  }

  /// Converts a figure the user typed in the display unit back to Toman.
  static double toToman(num amount, {Currency currency = Currency.toman}) {
    return amount / currency.multiplier;
  }

  /// Reads a figure out of a field that [GroupedAmountInputFormatter] has been
  /// grouping as the user typed.
  ///
  /// Separators and Persian digits both have to go: the phones run a Persian
  /// keyboard, so an amount can arrive as "۱٬۲۶۰٬۰۰۰" and `double.tryParse`
  /// would call that null and reject a perfectly good entry.
  static double? parseInput(String? typed) {
    if (typed == null) return null;

    final bare = _toLatinDigits(typed).replaceAll(RegExp(r'[/,٬\s]'), '');

    return bare.isEmpty ? null : double.tryParse(bare);
  }

  static String _toLatinDigits(String value) => latinDigits(value);
}

/// Groups an amount into threes while it is being typed.
///
/// In Rial the figures run an extra digit longer than the Toman ones the
/// shop is used to, and an unbroken "12600000" is read wrong often enough
/// that entries were being saved off by a factor of ten. Grouping as they
/// type gives the eye something to count.
///
/// The decimal tail is left alone — only the whole part is grouped — and the
/// caret is kept the same distance from the end of the text, so inserting a
/// separator does not throw the cursor back to the start.
class GroupedAmountInputFormatter extends TextInputFormatter {
  const GroupedAmountInputFormatter();

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final bare = MoneyFormat._toLatinDigits(newValue.text)
        .replaceAll(RegExp(r'[^0-9.]'), '');

    if (bare.isEmpty) return newValue.copyWith(text: '');

    final dot = bare.indexOf('.');
    final whole = dot == -1 ? bare : bare.substring(0, dot);
    // Everything after a second dot is dropped; "1.2.3" is not a number.
    final fraction =
        dot == -1 ? null : bare.substring(dot + 1).replaceAll('.', '');

    final digits = int.tryParse(whole);
    final text = StringBuffer(digits == null ? whole : MoneyFormat._group(digits));
    if (fraction != null) text.write('.$fraction');

    final formatted = text.toString();
    final fromEnd = newValue.text.length - newValue.selection.baseOffset;
    final offset = (formatted.length - fromEnd).clamp(0, formatted.length);

    return TextEditingValue(
      text: formatted,
      selection: TextSelection.collapsed(offset: offset),
    );
  }
}
