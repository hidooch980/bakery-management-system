/// خواندن پیامک بانک‌های ایرانی — برگردان SmsParser اپ «همدم» به Dart.
///
/// بی‌وابستگی به اندروید تا با آزمون واحد بشود سنجید. رقم فارسی و عربی،
/// جداکنندهٔ «,» «٬» «،»، ی و ک عربی و ریال/تومان را می‌فهمد. مبلغ همیشه
/// به تومان برمی‌گردد، چون سرور همه‌چیز را به تومان نگه می‌دارد.
enum BankSmsDirection { deposit, withdrawal, unknown }

class ParsedBankSms {
  const ParsedBankSms({
    required this.amountToman,
    required this.direction,
    this.balanceToman,
    this.bank,
    this.cardLast4,
  });

  final int? amountToman;
  final BankSmsDirection direction;
  final int? balanceToman;
  final String? bank;

  /// چهار رقم آخر کارت یا حساب، برای وصل کردن به حساب بانکی نانوایی.
  final String? cardLast4;
}

class BankSmsParser {
  const BankSmsParser._();

  /// شماره‌های پیامکی بانک‌ها (فهرست عمومی). کاربر می‌تواند اضافه کند.
  static const senderBank = <String, String>{
    '300017': 'ملی',
    '200033': 'ملت',
    '200030': 'ملت',
    '20000': 'سامان',
    '1000900': 'پاسارگاد',
    '50009000': 'پاسارگاد',
    '200070': 'تجارت',
    '20007010': 'تجارت',
    '200060': 'صادرات',
    '200020': 'سپه',
    '200021': 'سپه',
    '200022': 'سپه',
    '200038': 'سینا',
    '200050': 'اقتصاد نوین',
    '2000911': 'کشاورزی',
    '300014': 'مسکن',
    '300044': 'رفاه',
    '300066': 'رفاه',
    '300054': 'پارسیان',
    '300055': 'پارسیان',
    '50001099': 'پارسیان',
    '100088': 'سرمایه',
    '1000700': 'توسعه تعاون',
  };

  static const _otp = [
    'رمز پویا',
    'رمزپویا',
    'رمز یکبار',
    'رمز یک بار',
    'کد تایید',
    'کد تأیید',
    'کد فعالسازی',
    'کد ورود',
    'رمز عبور',
    'رمز:',
    'otp',
    'password',
  ];

  static String normalize(String s) {
    final b = StringBuffer();
    for (final r in s.runes) {
      if (r >= 0x06F0 && r <= 0x06F9) {
        b.writeCharCode(0x30 + r - 0x06F0);
      } else if (r >= 0x0660 && r <= 0x0669) {
        b.writeCharCode(0x30 + r - 0x0660);
      } else {
        final c = String.fromCharCode(r);
        b.write(switch (c) {
          'ي' || 'ى' => 'ی',
          'ك' => 'ک',
          '٬' || '،' || '\u066B' => ',',
          '\u200C' || '\u200F' || '\u200E' => ' ',
          '\u202A' ||
          '\u202B' ||
          '\u202C' ||
          '\u202D' ||
          '\u202E' ||
          '\u2066' ||
          '\u2067' ||
          '\u2068' ||
          '\u2069' =>
            '',
          '\u2212' || '\u2013' => '-',
          _ => c,
        });
      }
    }
    return b.toString();
  }

  static String normalizeSender(String s) {
    var n = normalize(s).trim().toLowerCase().replaceAll(RegExp(r'[\s-]'), '');
    if (n.startsWith('+98')) n = n.substring(3);
    if (n.startsWith('0098')) n = n.substring(4);
    return n;
  }

  static bool isOtp(String body) {
    final n = normalize(body).toLowerCase();
    return _otp.any(n.contains);
  }

  static bool isBankSender(String sender, [Iterable<String>? extra]) {
    final s = normalizeSender(sender);
    if (s.isEmpty) return false;
    final all = {...senderBank.keys, ...?extra}.map(normalizeSender);
    return all.any((e) =>
        e.isNotEmpty &&
        (s == e ||
            (s.startsWith('98') && s.endsWith(e) && s.length > e.length)));
  }

  /// پیامک بانکی است و رمز یکبار نیست.
  static bool isBankMessage(String sender, String body,
          [Iterable<String>? extra]) =>
      !isOtp(body) && isBankSender(sender, extra);

  static int? _num(String v) => int.tryParse(v.replaceAll(',', ''));

  static ParsedBankSms parse(String sender, String body) {
    final n = normalize(body);
    final toman = n.contains('تومان') && !n.contains('ریال');
    int conv(int v) => toman ? v : v ~/ 10;

    int? balance;
    int balanceStart = -1;
    final bm = RegExp(r'(مانده|موجودی)[^0-9+\n-]{0,20}([+-]?)[ \t]*([0-9][0-9,]*)')
        .firstMatch(n);
    if (bm != null) {
      final v = _num(bm.group(3)!);
      if (v != null) {
        balance = conv(v) * (bm.group(2) == '-' ? -1 : 1);
        balanceStart = bm.start;
      }
    }

    int? amount;
    var dir = BankSmsDirection.unknown;
    final labeled = RegExp(
        r'(برداشت|واریز|مبلغ|خرید|انتقال|کسر|پرداخت|بستانکار|بدهکار|دریافت|سود)[^0-9+\n-]{0,25}([+-]?)\s*([0-9][0-9,]*)');
    for (final m in labeled.allMatches(n)) {
      if (m.start == balanceStart) continue;
      // «واریز پایا به حساب 0101…»: that number is the account, not money.
      if (RegExp(r'حساب|کارت|شماره').hasMatch(m.group(0)!)) continue;
      final v = _num(m.group(3)!);
      if (v == null || v < 100) continue;
      amount = conv(v);
      dir = _fromLabel(m.group(1)!, m.group(2)!);
      break;
    }
    if (amount == null) {
      final sm = RegExp(r'(^|[\s:])([+-])\s?([0-9][0-9,]{2,})').firstMatch(n);
      if (sm != null) {
        amount = conv(_num(sm.group(3)!) ?? 0);
        dir = sm.group(2) == '+'
            ? BankSmsDirection.deposit
            : BankSmsDirection.withdrawal;
      }
    }
    // Sepah heads its card-reader settlement «پرداخت گروهی»; the money comes in.
    if (n.contains('گروهی') && n.contains('کارتخوان')) {
      dir = BankSmsDirection.deposit;
    }
    if (dir == BankSmsDirection.unknown) {
      final w = RegExp(r'برداشت|خرید|بدهکار|کسر|پرداخت').firstMatch(n)?.start;
      final d = RegExp(r'واریز|بستانکار|دریافت|سود').firstMatch(n)?.start;
      if (w != null && (d == null || w < d)) dir = BankSmsDirection.withdrawal;
      if (d != null && (w == null || d < w)) dir = BankSmsDirection.deposit;
    }

    // «کارت 6037**1234» یا «حساب 0987»: چهار رقم آخر همان شماره.
    final token = RegExp(r'(?:کارت|حساب)[^0-9*\n]{0,6}([0-9*.\-]{4,})')
        .firstMatch(n)
        ?.group(1)
        ?.replaceAll(RegExp(r'[^0-9]'), '');
    final card = token != null && token.length >= 4
        ? token.substring(token.length - 4)
        : null;

    return ParsedBankSms(
      amountToman: amount,
      direction: dir,
      balanceToman: balance,
      bank: senderBank[normalizeSender(sender)],
      cardLast4: card,
    );
  }

  static BankSmsDirection _fromLabel(String label, String sign) {
    if (sign == '+') return BankSmsDirection.deposit;
    if (sign == '-') return BankSmsDirection.withdrawal;
    return switch (label) {
      'واریز' || 'بستانکار' || 'دریافت' || 'سود' => BankSmsDirection.deposit,
      'برداشت' ||
      'خرید' ||
      'کسر' ||
      'پرداخت' ||
      'بدهکار' =>
        BankSmsDirection.withdrawal,
      _ => BankSmsDirection.unknown,
    };
  }
}
