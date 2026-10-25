import 'package:flutter/material.dart';

/// سهم روزانه آرد در همان کارت سهمیه دوره یا کل ماه.
class DailyFlourQuota extends StatelessWidget {
  const DailyFlourQuota({super.key, required this.period});

  final Map<String, dynamic> period;

  @override
  Widget build(BuildContext context) {
    final bags = num.tryParse('${period['daily_pace_bags']}');
    final days = int.tryParse('${period['working_days']}');

    // پاسخ سرور قدیمی سهم روزانه ندارد؛ مقدار نامعلوم صفر نمایش داده نمی‌شود.
    if (bags == null || !bags.isFinite || days == null) {
      return const SizedBox.shrink();
    }

    final theme = Theme.of(context);
    final amount = bags.toStringAsFixed(2).replaceFirst(RegExp(r'\.?0+$'), '');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('سهم روزانه آرد', style: theme.textTheme.titleSmall),
        const SizedBox(height: 6),
        Text(
          days > 0 ? '$amount کیسه در روز کاری' : 'روز کاری در این بازه ثبت نشده است.',
          style: theme.textTheme.titleMedium?.copyWith(
            color: theme.colorScheme.primary,
            fontWeight: FontWeight.w800,
          ),
        ),
        if (days > 0)
          Text(
            'سهمیه این بازه ÷ $days روز کاری؛ تعطیلات ثبت‌شده کسر شده‌اند.',
            style: theme.textTheme.bodySmall,
          ),
        const Divider(height: 26),
      ],
    );
  }
}
