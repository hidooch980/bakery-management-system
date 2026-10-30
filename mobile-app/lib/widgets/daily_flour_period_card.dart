import 'package:flutter/material.dart';

/// نمای مستقل روزانه؛ تاریخ از پاسخ سرور می‌آید تا کش قدیمی، امروز خوانده نشود.
class DailyFlourPeriodCard extends StatelessWidget {
  const DailyFlourPeriodCard({super.key, required this.period});

  final Map<String, dynamic>? period;

  static String _number(dynamic value) {
    final number = value is num ? value : num.tryParse('$value');
    if (number == null || !number.isFinite) return '—';
    return number.toStringAsFixed(number == number.roundToDouble() ? 0 : 2);
  }

  @override
  Widget build(BuildContext context) {
    final data = period;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('دوره روزانه', style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 8),
            if (data == null)
              const Text('اطلاعات دوره روزانه از سرور دریافت نشد؛ به‌روزرسانی سرور را بررسی کنید.')
            else ...[
              Text('${data['date_label'] ?? data['date'] ?? '—'} • ${data['period_label'] ?? ''}'),
              if (data['is_holiday'] == true)
                const Text('تعطیلی ثبت‌شده؛ سهمیه این روز صفر است.'),
              const Divider(),
              _line('سهمیه روز', '${_number(data['allocated_bags'])} کیسه • ${_number(data['allocated_kg'])} کیلوگرم'),
              _line('مصرف روز', '${_number(data['used_bags'])} کیسه • ${_number(data['used_kg'])} کیلوگرم'),
              _line('مانده سهم روز', '${_number(data['remaining_bags'])} کیسه'),
              const Divider(),
              _line('معادل نان سهم روز', '${_number(data['allocated_bread_count'])} نان'),
              _line('چانه تولیدشده روز', '${_number(data['chane_count'])} چانه'),
              _line('فروش کارتخوان روز', '${_number(data['card_bread_count'])} نان'),
              _line('مبلغ کارتخوان روز', '${data['card_amount_formatted'] ?? '—'}'),
              _line('اختلاف سهم و فروش روز', '${_number(data['bread_remainder'])} نان'),
              const SizedBox(height: 8),
              Text('${data['source_note'] ?? ''}', style: Theme.of(context).textTheme.bodySmall),
              Text('${data['quota_note'] ?? ''}', style: Theme.of(context).textTheme.bodySmall),
            ],
          ],
        ),
      ),
    );
  }

  Widget _line(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(child: Text(label)),
            const SizedBox(width: 12),
            Flexible(child: Text(value, textAlign: TextAlign.end)),
          ],
        ),
      );
}
