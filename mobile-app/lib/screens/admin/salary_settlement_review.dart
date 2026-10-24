import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../utils/formatters.dart';

/// پیش‌نمایش بر پایه همان ترتیب سرور: ابتدا مساعده، سپس بدهی نان.
class SalarySettlement {
  const SalarySettlement({
    required this.base,
    required this.bonus,
    required this.deduction,
    required this.advanceOwed,
    required this.breadOwed,
    required this.recoverAdvances,
    required this.recoverBread,
  });

  final double base, bonus, deduction, advanceOwed, breadOwed;
  final bool recoverAdvances, recoverBread;

  double get gross => base + bonus - deduction;
  double get advance =>
      recoverAdvances ? math.min(advanceOwed, math.max(0.0, gross)) : 0;
  double get bread =>
      recoverBread ? math.min(breadOwed, math.max(0.0, gross - advance)) : 0;
  double get net => gross - advance - bread;
  double get advanceRemaining => advanceOwed - advance;
  double get breadRemaining => breadOwed - bread;
}

/// این صفحه فقط تأیید مدیر را می‌گیرد و هیچ پرداختی ثبت نمی‌کند.
class SalarySettlementReview extends StatelessWidget {
  const SalarySettlementReview({
    super.key,
    required this.personName,
    required this.period,
    required this.accountTitle,
    required this.currency,
    required this.settlement,
    this.note,
  });

  final String personName, period, accountTitle, currency;
  final SalarySettlement settlement;
  final String? note;

  @override
  Widget build(BuildContext context) {
    final s = settlement;
    return AlertDialog(
      title: const Text('پیش‌نمایش تسویه'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(personName, style: Theme.of(context).textTheme.titleMedium),
            Text('دوره: ${persianDigits(period)}'),
            Text('محل پرداخت: $accountTitle'),
            const SizedBox(height: 12),
            _line('حقوق پایه', s.base),
            _line('پاداش و اضافه‌کاری ثبت‌شده', s.bonus),
            _line('کسورات دستی', -s.deduction),
            _line('کسر علی‌الحساب', -s.advance),
            _line('کسر بدهی نان منزل', -s.bread),
            const Divider(),
            _line('خالص قابل پرداخت', s.net, strong: true),
            const Divider(),
            _line('مانده علی‌الحساب پس از تسویه', s.advanceRemaining),
            _line('مانده بدهی نان پس از تسویه', s.breadRemaining),
            if (note != null) Text('توضیح: $note'),
            const SizedBox(height: 12),
            const Text(
                'بدهی‌ها از پرونده تازه خوانده شده‌اند. فقط پس از انجام پرداخت، ثبت نهایی را بزنید.'),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context, false),
          child: const Text('بازگشت بدون ثبت'),
        ),
        FilledButton(
          onPressed: () => Navigator.pop(context, true),
          child: const Text('تأیید و ثبت تسویه'),
        ),
      ],
    );
  }

  Widget _line(String label, double amount, {bool strong = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Text('$label: ${MoneyFormat.plain(amount)} $currency',
            style: TextStyle(
                fontWeight: strong ? FontWeight.bold : FontWeight.normal)),
      );
}
