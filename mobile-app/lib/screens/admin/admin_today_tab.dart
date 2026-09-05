import 'package:flutter/material.dart';

import '../../models/today_answer.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// One answer: whether the shop is sound, and what is the owner's to do.
///
/// The owner's half of «یک کار». The production roles are asked one
/// question a screen; he is given one answer — and until now he was given
/// four tabs of figures and left to work it out.
///
/// It matters that this is on the phone and not only in the panel. He is
/// more often beside the oven than at a desk, and an answer he can only
/// get by sitting down is an answer he goes on getting by asking a person
/// instead. That is how a 400 kg hole in the ledger survived four days of
/// screens that all said green: nothing was wrong with the figures he
/// could see, and the one that would have told him was a command over SSH.
///
/// Every sentence here is composed on the server. The phone draws what it
/// is given and invents nothing, so the panel and the phone cannot come to
/// different conclusions about the same shop.
class AdminTodayTab extends StatefulWidget {
  const AdminTodayTab({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminTodayTab> createState() => _AdminTodayTabState();
}

class _AdminTodayTabState extends State<AdminTodayTab> {
  late Future<TodayAnswer> _answer;

  @override
  void initState() {
    super.initState();
    _answer = widget.api.today();
  }

  void _reload() => setState(() => _answer = widget.api.today());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<TodayAnswer>(
        future: _answer,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [ErrorBox(message: '${snapshot.error}', onRetry: _reload)],
            );
          }

          return _Answer(
            answer: snapshot.data!,
            checkedAt: widget.api.todayCheckedAt(),
          );
        },
      ),
    );
  }
}

class _Answer extends StatelessWidget {
  const _Answer({required this.answer, required this.checkedAt});

  final TodayAnswer answer;

  /// When this answer was fetched, or null when it came from the server
  /// just now. A saved copy must not say «همین حالا»: a green screen
  /// with the wrong time on it is the four days this page was built
  /// against.
  final DateTime? checkedAt;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;

    // Sound reads in the shop's own accent; broken reads in the colour
    // this palette keeps for money going the wrong way, which is the only
    // red it has and the only one it needs.
    final toneColour = switch (answer.tone) {
      TodayTone.fail => AppColors.moneyOut,
      _ => AppColors.signalFor(theme.brightness),
    };

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 32),
      children: [
        Text(
          answer.system,
          style: theme.textTheme.headlineMedium?.copyWith(
            fontWeight: FontWeight.w900,
            color: toneColour,
            height: 1.3,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          answer.yours,
          style: theme.textTheme.titleMedium?.copyWith(
            color: muted,
            height: 1.4,
          ),
        ),

        const SizedBox(height: 14),

        // The stamp. A green screen with no time on it is exactly what
        // those four days looked like.
        Row(
          children: [
            Icon(
              checkedAt == null
                  ? Icons.check_circle_outline_rounded
                  : Icons.history_rounded,
              size: 15,
              color: muted,
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                checkedAt == null
                    ? 'هر ${answer.cycles} چرخه همین حالا بررسی شد'
                    : 'هر ${answer.cycles} چرخه در ${JalaliFormat.dateTime(checkedAt!)} بررسی شد — بدون اتصال، به‌روز نیست',
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
            ),
          ],
        ),

        const SizedBox(height: 20),
        const Divider(height: 1),

        // A contradiction in the records comes before the shop's own
        // business, and says plainly that the figures below are not to be
        // trusted until it is settled.
        if (!answer.sound) ...[
          const SizedBox(height: 16),
          _Broken(failures: answer.failures),
        ],

        if (answer.needs.isEmpty && answer.warnings.isEmpty) ...[
          const SizedBox(height: 24),
          Text(
            'هیچ چیزی منتظر شما نیست.',
            style: theme.textTheme.bodyMedium?.copyWith(color: muted),
          ),
        ],

        for (final need in answer.needs) _Need(need: need),

        // Cycle warnings are the shop's to look at but are not faults, so
        // they sit under the issues rather than beside the sentence.
        for (final warning in answer.warnings)
          _Need(
            need: TodayNeed(
              key: 'cycle',
              severity: 'warning',
              title: warning,
              detail: '',
              suggestion: '',
            ),
          ),

        if (answer.figures.isNotEmpty) ...[
          const SizedBox(height: 22),
          const Divider(height: 1),
          const SizedBox(height: 14),
          Wrap(
            spacing: 18,
            runSpacing: 6,
            children: [
              for (final figure in answer.figures)
                RichText(
                  text: TextSpan(
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    children: [
                      TextSpan(text: '${figure.label} '),
                      TextSpan(
                        text: figure.value,
                        style: theme.textTheme.bodySmall?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: theme.textTheme.bodyMedium?.color,
                        ),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ],
      ],
    );
  }
}

class _Broken extends StatelessWidget {
  const _Broken({required this.failures});

  final List<String> failures;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.moneyOut),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'سیستم با خودش نمی‌خواند',
            style: theme.textTheme.titleSmall?.copyWith(
              fontWeight: FontWeight.w700,
              color: AppColors.moneyOut,
            ),
          ),
          const SizedBox(height: 6),
          for (final failure in failures)
            Padding(
              padding: const EdgeInsets.only(bottom: 3),
              child: Text('• $failure', style: theme.textTheme.bodySmall),
            ),
        ],
      ),
    );
  }
}

/// One line of work, with a stripe that says how loudly.
///
/// The stripe is `attention`, never the accent: ten places in the admin
/// surface once used the yellow to mean «overdue», which after the repaint
/// would have made every warning look like a button.
class _Need extends StatelessWidget {
  const _Need({required this.need});

  final TodayNeed need;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;

    final stripe = need.isCritical
        ? AppColors.attention
        : need.isWarning
            ? AppColors.emberWarm
            : theme.dividerColor;

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border: Border(bottom: BorderSide(color: theme.dividerColor, width: 0.5)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            width: 3,
            constraints: const BoxConstraints(minHeight: 34),
            decoration: BoxDecoration(
              color: stripe,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  need.title,
                  style: theme.textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w600,
                  ),
                ),
                if (need.detail.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    need.detail,
                    style: theme.textTheme.bodySmall?.copyWith(color: muted),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
