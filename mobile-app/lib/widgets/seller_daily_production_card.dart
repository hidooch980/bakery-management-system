import 'package:flutter/material.dart';

import '../models/chane_board.dart';

/// کیسه‌های ثبت‌شده امروز، از همان پاسخ تولید صفحه فروشنده.
/// خمیر در انتظار چانه‌گیری هم در این عدد هست؛ پخت تکمیل‌شده نیست.
class SellerDailyProductionCard extends StatelessWidget {
  const SellerDailyProductionCard({
    super.key,
    required this.board,
    required this.onRetry,
  });

  final ChaneBoard? board;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final production = board;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('پخت روزانه', style: theme.textTheme.titleMedium),
            if (production == null) ...[
              const SizedBox(height: 8),
              const Text('اطلاعات پخت امروز دریافت نشد.'),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton(
                  onPressed: onRetry,
                  child: const Text('دریافت دوباره'),
                ),
              ),
            ] else ...[
              if (production.dateDisplay.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(production.dateDisplay,
                    style: theme.textTheme.bodySmall),
              ],
              const SizedBox(height: 12),
              Text(
                '${production.doughBagsToday} کیسه',
                style: theme.textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: theme.colorScheme.primary,
                ),
              ),
              const SizedBox(height: 4),
              const Text('خمیر ثبت‌شده امروز؛ شامل خمیرهای در انتظار چانه‌گیری'),
            ],
          ],
        ),
      ),
    );
  }
}
