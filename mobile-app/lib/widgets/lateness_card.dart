import 'package:flutter/material.dart';

import '../services/bakery_api.dart';
import '../theme/app_theme.dart';

/// A person's own late record, on their own screen.
///
/// Lateness has been recorded and priced on an escalating scale since the
/// tariff went in, and reported — to the manager. `late-report` sits
/// behind a permission, so the person it is about had no way to see it.
/// They found out how many late days they had when somebody told them, or
/// when it came off their wages.
///
/// That is the wrong order. A tariff nobody can check is a fine, not a
/// rule, and the whole point of an escalating one is that the next step
/// can be seen coming while there is still time to avoid it.
///
/// So the figure this leads with is not what they owe. It is how many free
/// days are left, and what the next late day would cost — the only two
/// numbers on the screen anybody can still do something about.
class LatenessCard extends StatefulWidget {
  const LatenessCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<LatenessCard> createState() => _LatenessCardState();
}

class _LatenessCardState extends State<LatenessCard> {
  late Future<Map<String, dynamic>> _record;

  @override
  void initState() {
    super.initState();
    _record = widget.api.myLateness();
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _record,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting ||
            snapshot.hasError ||
            snapshot.data == null) {
          // Nothing rather than an error box. This is not the reason
          // anybody opened the screen, and a red panel about the server
          // over somebody's own record reads worse than silence.
          return const SizedBox.shrink();
        }

        final data = snapshot.data!;
        final lateDays = data['late_days'] as int? ?? 0;
        final freeLeft = data['free_days_left'] as int? ?? 0;
        final scheme = Theme.of(context).colorScheme;

        // A clean month is worth saying out loud, and briefly.
        if (lateDays == 0) {
          return Card(
            child: ListTile(
              leading: Icon(Icons.check_circle_rounded, color: AppColors.moneyIn),
              title: const Text('این ماه دیر نیامده‌اید'),
              subtitle: Text('${data['period_label']}'),
            ),
          );
        }

        final owing = freeLeft == 0;

        return Card(
          color: owing ? AppColors.attention.withValues(alpha: 0.08) : null,
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(
                      Icons.schedule_rounded,
                      size: IconSize.button,
                      color: owing ? AppColors.attention : scheme.onSurfaceVariant,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'تأخیر — ${data['period_label']}',
                        style: Theme.of(context)
                            .textTheme
                            .bodyMedium
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ),
                    Text(
                      '$lateDays روز',
                      style: Theme.of(context)
                          .textTheme
                          .bodyMedium
                          ?.copyWith(fontWeight: FontWeight.w700),
                    ),
                  ],
                ),
                const SizedBox(height: 10),

                // The two figures worth reading first, in this order. What
                // is still in hand comes before what has already gone.
                if (freeLeft > 0)
                  Text(
                    '$freeLeft روز مهلت بدون جریمه باقی مانده',
                    style: Theme.of(context)
                        .textTheme
                        .bodyMedium
                        ?.copyWith(color: AppColors.moneyIn),
                  )
                else
                  Text(
                    'جریمه تا اینجا: ${data['penalty_formatted']}',
                    style: Theme.of(context)
                        .textTheme
                        .bodyMedium
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),

                Text(
                  'روز تأخیر بعدی: ${data['next_late_day_costs_formatted']}',
                  style: Theme.of(context)
                      .textTheme
                      .bodySmall
                      ?.copyWith(color: scheme.onSurfaceVariant),
                ),

                const Divider(height: 20),

                // The times, not a verdict. «You were late» is an
                // accusation; «06:25, deadline 06:00» is something a
                // person can check against their own morning.
                for (final row in (data['recent'] as List? ?? const [])
                    .cast<Map<String, dynamic>>()
                    .where((r) => r['is_late'] == true)
                    .take(5))
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 2),
                    child: Row(
                      children: [
                        SizedBox(
                          width: 86,
                          child: Text(
                            '${row['date_jalali']}',
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: scheme.onSurfaceVariant),
                          ),
                        ),
                        Expanded(
                          child: Text(
                            '${row['started_at']}  (مهلت ${_hhmm(row['deadline'])})',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ),
                        Text(
                          '${row['late_minutes']} دقیقه',
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(color: AppColors.attention),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  /// The server sends `06:00:00`; the seconds say nothing to anybody.
  String _hhmm(Object? value) {
    final text = '${value ?? ''}';

    return text.length >= 5 ? text.substring(0, 5) : text;
  }
}
