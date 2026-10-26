import 'package:flutter/material.dart';

import '../../models/today_answer.dart';
import 'today_actions.dart';
import 'bank_sms/bank_sms_pages.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import '../../widgets/role_home_scaffold.dart';

/// وضعیت نانوایی و کارهای منتظر مدیر از پاسخ مشترک سرور نمایش داده می‌شوند.
/// هشدارهای وضعیت از سرور می‌آیند تا نتیجه پنل و گوشی یکسان بماند.
class AdminTodayTab extends StatefulWidget {
  const AdminTodayTab({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminTodayTab> createState() => _AdminTodayTabState();
}

class _AdminTodayTabState extends State<AdminTodayTab> {
  late Future<TodayAnswer> _answer;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _answer = widget.api.today();
  }

  void _reload() => setState(() {
        _generation++;
        _answer = widget.api.today();
      });

  @override
  Widget build(BuildContext context) {
    final hub = BankSmsHub.of(widget.api);
    return Column(children: [
      // Only once something is waiting; the switch lives in «مالی».
      ListenableBuilder(
        listenable: hub,
        builder: (_, __) => hub.openCount > 0
            ? Padding(
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                child: BankSmsHomeEntry(hub: hub))
            : const SizedBox.shrink(),
      ),
      Expanded(child: RefreshIndicator(
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
              children: [
                ErrorBox(message: '${snapshot.error}', onRetry: _reload),
                TodayActions(
                    key: ValueKey(_generation),
                    api: widget.api,
                    needs: const [],
                    openNeed: (_) {},
                    onReturn: _reload),
              ],
            );
          }

          return _Answer(
            key: ValueKey(_generation),
            api: widget.api,
            onReturn: _reload,
            answer: snapshot.data!,
            checkedAt: widget.api.todayCheckedAt(),
          );
        },
      ),
    )),
    ]);
  }
}

class _Answer extends StatelessWidget {
  const _Answer(
      {super.key,
      required this.api,
      required this.onReturn,
      required this.answer,
      required this.checkedAt});
  final BakeryApi api;
  final VoidCallback onReturn;

  final TodayAnswer answer;

  /// زمان دریافت نسخه ذخیره‌شده؛ پاسخ قدیمی نباید «همین حالا» معرفی شود.
  final DateTime? checkedAt;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;

    // وضعیت ناسازگار با رنگ هشدار و وضعیت عادی با رنگ اصلی نمایش داده می‌شود.
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
          answer.sound && answer.needs.isEmpty
              ? 'کارهای مالی و درخواست‌ها را در بخش زیر بررسی کنید.'
              : answer.yours,
          style: theme.textTheme.titleMedium?.copyWith(
            color: muted,
            height: 1.4,
          ),
        ),

        const SizedBox(height: 14),

        // زمان بررسی برای تشخیص پاسخ قدیمی از نتیجه تازه لازم است.
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

        // ناسازگاری دفتر پیش از کارهای روزانه نمایش داده می‌شود تا مدیر ابتدا اعتبار ارقام را بررسی کند.
        if (!answer.sound) ...[
          const SizedBox(height: 16),
          _Broken(failures: answer.failures),
        ],

        if (answer.needs.isEmpty && answer.warnings.isEmpty) ...[
          const SizedBox(height: 24),
          Text(
            'هشداری در گزارش وضعیت نانوایی ثبت نشده است.',
            style: theme.textTheme.bodyMedium?.copyWith(color: muted),
          ),
        ],

        TodayActions(
            api: api,
            needs: answer.needs,
            onReturn: onReturn,
            openNeed: (need) => showModalBottomSheet<void>(
                context: context,
                isScrollControlled: true,
                showDragHandle: true,
                builder: (_) => _NeedSheet(need: need))),
        for (final need in answer.needs.where((need) => !isTodayAction(need)))
          _Need(need: need),

        // هشدار چرخه کار قابل بررسی است و به معنی خرابی ثبت‌ها نیست.
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

        // پیش‌بینی پس از کارها و پیش از ارقام خلاصه، همراه مبنای محاسبه نمایش داده می‌شود.
        if (answer.outlook.isNotEmpty) ...[
          const SizedBox(height: 22),
          const Divider(height: 1),
          const SizedBox(height: 14),
          for (final line in answer.outlook) _OutlookLine(line: line),
        ],

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

/// پیش‌بینی همراه مبنای محاسبه تا مدیر بتواند نتیجه را بررسی کند.
class _OutlookLine extends StatelessWidget {
  const _OutlookLine({required this.line});

  final TodayOutlook line;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            line.title,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight:
                  line.needsAttention ? FontWeight.w700 : FontWeight.w500,
              color: line.needsAttention ? AppColors.emberWarm : null,
            ),
          ),
          if (line.basis.isNotEmpty) ...[
            const SizedBox(height: 2),
            Text(
              line.basis,
              style: theme.textTheme.bodySmall?.copyWith(color: muted),
            ),
          ],
        ],
      ),
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

/// شدت هشدار با نوار مشخص می‌شود؛ رنگ هشدار با رنگ دکمه‌ها اشتباه نمی‌شود.
class _Need extends StatelessWidget {
  const _Need({required this.need});

  final TodayNeed need;

  /// فقط ردیفی باز می‌شود که علت، پیشنهاد یا مسیر پیگیری داشته باشد.
  bool get _hasMore =>
      need.cause.isNotEmpty ||
      need.suggestion.isNotEmpty ||
      need.destination != null;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;

    final stripe = need.isCritical
        ? AppColors.attention
        : need.isWarning
            ? AppColors.emberWarm
            : theme.dividerColor;

    final row = Container(
      padding: const EdgeInsets.symmetric(vertical: 12),
      decoration: BoxDecoration(
        border:
            Border(bottom: BorderSide(color: theme.dividerColor, width: 0.5)),
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

          // پیکان نشان می‌دهد ردیف جزئیات قابل باز شدن دارد.
          if (_hasMore) ...[
            const SizedBox(width: 8),
            Icon(Icons.chevron_left_rounded, size: 20, color: muted),
          ],
        ],
      ),
    );

    if (!_hasMore) return row;

    return InkWell(onTap: () => _open(context), child: row);
  }

  void _open(BuildContext context) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _NeedSheet(need: need),
    );
  }
}

/// جزئیات هشدار شامل مسئله، علت احتمالی، پیشنهاد و مسیر پیگیری است.
class _NeedSheet extends StatelessWidget {
  const _NeedSheet({required this.need});

  final TodayNeed need;

  /// نام مقصد باید با عنوان تب در نوار برنامه یکسان باشد.
  static const _tabNames = {
    'overview': 'خلاصه',
    'finance': 'مالی',
    'warehouse': 'انبار',
    'staff': 'کارکنان',
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;
    final tabs = HomeTabs.of(context);
    final name = _tabNames[need.destination];

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              need.title,
              style: theme.textTheme.titleMedium?.copyWith(
                fontWeight: FontWeight.w800,
                color: need.isCritical ? AppColors.attention : null,
              ),
            ),

            if (need.detail.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                need.detail,
                style: theme.textTheme.bodyMedium?.copyWith(color: muted),
              ),
            ],

            if (need.cause.isNotEmpty) ...[
              const SizedBox(height: 16),
              _Part(title: 'چرا', body: need.cause),
            ],

            if (need.suggestion.isNotEmpty) ...[
              const SizedBox(height: 14),
              _Part(title: 'چه کنید', body: need.suggestion),
            ],

            // دکمه مسیر فقط در صفحه‌ای نمایش داده می‌شود که ناوبری تب‌ها در دسترس باشد.
            if (name != null && tabs != null) ...[
              const SizedBox(height: 20),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: () {
                    Navigator.of(context).pop();
                    tabs.goTo(need.destination!);
                  },
                  icon: const Icon(Icons.arrow_back_rounded, size: 18),
                  label: Text('رفتن به «$name»'),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Part extends StatelessWidget {
  const _Part({required this.title, required this.body});

  final String title;
  final String body;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          title,
          style: theme.textTheme.labelMedium?.copyWith(
            fontWeight: FontWeight.w700,
            color: theme.textTheme.bodySmall?.color,
          ),
        ),
        const SizedBox(height: 3),
        Text(body, style: theme.textTheme.bodyMedium),
      ],
    );
  }
}
