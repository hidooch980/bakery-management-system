import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/entries.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../services/last_used.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';
import '../../widgets/one_task.dart';
import '../shared/me_screen.dart';
import '../shared/settings_screen.dart';

/// The chane maker's whole app: two questions.
///
/// «کدام خمیر؟» — and only when there is more than one waiting, because a
/// choice between one thing is not a choice. Then «چند چانه شد؟».
///
/// The count is the one number in the shop that is different every single
/// time, so it is typed rather than stepped, on a keypad the app draws
/// itself: Persian digits, thumb-sized keys, and nothing sliding up over
/// the answer while it is entered. A count more than a fifth away from
/// what the batch should yield is coloured and questioned before it is
/// saved — asked, not refused, because the shop knows its trade better
/// than the formula does.
class ChaneHomeScreen extends StatefulWidget {
  const ChaneHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<ChaneHomeScreen> createState() => _ChaneHomeScreenState();
}

enum _Stage { loading, nothingWaiting, choosing, counting, extras, done }

class _ChaneHomeScreenState extends State<ChaneHomeScreen> {
  /// How far off the expected yield a count has to be before the app says
  /// something. A fifth: wide enough that an ordinary good or bad batch
  /// passes without comment, narrow enough to catch a slipped digit.
  static const _questionAt = 0.2;

  _Stage _stage = _Stage.loading;

  List<DoughEntry> _waiting = const [];
  DoughEntry? _chosen;

  int _count = 0;
  double _spray = 5;
  int _nanino = 0;

  Bakery? _bakery;
  bool _saving = false;
  bool _queued = false;
  double? _weightKg;

  @override
  void initState() {
    super.initState();
    _prepare();
  }

  Future<void> _prepare() async {
    _spray = await LastUsed.sprayFlourKg();
    _nanino = await LastUsed.naninoCount();

    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // The expected yield is a hint, not the question.
    }

    await _loadWaiting();
  }

  Future<void> _loadWaiting() async {
    try {
      final waiting = await widget.api.pendingDough();

      if (!mounted) return;
      setState(() {
        _waiting = waiting;

        if (waiting.isEmpty) {
          _stage = _Stage.nothingWaiting;
        } else if (waiting.length == 1) {
          // One batch is not a choice. Skip straight to the count.
          _chosen = waiting.first;
          _stage = _Stage.counting;
        } else {
          _stage = _Stage.choosing;
        }
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _stage = _Stage.nothingWaiting);
      showMessage(context, e.message, isError: true);
    }
  }

  /// What this batch should yield, from the shop's own dough formula.
  /// Null when the formula has not been set, in which case nothing is
  /// claimed rather than a figure guessed at.
  int? get _expected {
    final perBag = _bakery?.normalChanePerBag;
    final dough = _chosen;

    if (perBag == null || perBag <= 0 || dough == null) return null;

    return perBag * dough.bagCount;
  }

  bool get _looksWrong {
    final expected = _expected;

    if (expected == null || expected == 0 || _count == 0) return false;

    return (_count - expected).abs() / expected > _questionAt;
  }

  Future<void> _save() async {
    final dough = _chosen;

    if (dough == null || _count <= 0 || _saving) return;

    setState(() => _saving = true);

    try {
      final result = await widget.api.recordChane(
        doughEntryId: dough.id,
        chaneCount: _count,
        naninoChaneCount: _nanino,
        sprayFlourKg: _spray,
      );

      await LastUsed.rememberSprayFlourKg(_spray);
      await LastUsed.rememberNaninoCount(_nanino);

      if (!mounted) return;
      setState(() {
        _queued = result.queued;
        _weightKg = result.weightKg;
        _stage = _Stage.done;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _startOver() async {
    setState(() {
      _stage = _Stage.loading;
      _chosen = null;
      _count = 0;
    });

    await _loadWaiting();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_bakery?.name ?? 'چانه‌گیری'),
        centerTitle: false,
        titleTextStyle: Theme.of(context).textTheme.titleMedium,
        actions: [
          IconButton(
            tooltip: 'حساب من',
            icon: const Icon(Icons.person_outline_rounded),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => MeScreen(api: widget.api)),
            ),
          ),
          IconButton(
            tooltip: 'تنظیمات',
            icon: const Icon(Icons.settings_outlined),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => SettingsScreen(api: widget.api)),
            ),
          ),
        ],
      ),
      body: switch (_stage) {
        _Stage.loading => const Center(child: CircularProgressIndicator()),
        _Stage.nothingWaiting => _nothing(),
        _Stage.choosing => _choose(),
        _Stage.counting => _count_(),
        _Stage.extras => _extras(),
        _Stage.done => _done(),
      },
    );
  }

  Widget _nothing() {
    final theme = Theme.of(context);

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.hourglass_empty_rounded,
              size: 56,
              color: theme.colorScheme.onSurface.withValues(alpha: 0.35),
            ),
            const SizedBox(height: 18),
            Text(
              'خمیری در انتظار نیست',
              style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            Text(
              'وقتی خمیرگیر دسته‌ای ثبت کند، همین‌جا می‌آید.',
              textAlign: TextAlign.center,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
              ),
            ),
            const SizedBox(height: 26),
            OutlinedButton.icon(
              onPressed: _startOver,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('دوباره نگاه کن'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _choose() {
    final theme = Theme.of(context);

    return OneTaskScaffold(
      question: 'کدام خمیر را چانه گرفتی؟',
      step: 1,
      of: 2,
      actionLabel: 'ادامه',
      onAction: _chosen == null ? null : () => setState(() => _stage = _Stage.counting),
      child: ListView.separated(
        shrinkWrap: true,
        itemCount: _waiting.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (_, i) {
          final dough = _waiting[i];
          final picked = _chosen?.id == dough.id;

          return Material(
            color: picked
                ? AppColors.signal.withValues(alpha: 0.14)
                : theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(12),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: () => setState(() => _chosen = dough),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
                decoration: BoxDecoration(
                  border: Border.all(
                    color: picked ? AppColors.signal : Colors.transparent,
                    width: 2,
                  ),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Row(
                  children: [
                    Text(
                      '${dough.bagCount}',
                      style: theme.textTheme.headlineMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(width: 10),
                    Text('کیسه', style: theme.textTheme.titleMedium),
                    const Spacer(),
                    Text(
                      dough.userName ?? '',
                      style: theme.textTheme.bodySmall?.copyWith(
                        color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _count_() {
    final expected = _expected;
    final many = _waiting.length > 1;

    return OneTaskScaffold(
      question: 'چند چانه شد؟',
      step: many ? 2 : null,
      of: many ? 2 : null,
      onBack: many ? () => setState(() => _stage = _Stage.choosing) : null,
      hint: switch (null) {
        _ when _looksWrong && expected != null =>
          'از ${_chosen!.bagCount} کیسه معمولاً حدود $expected چانه درمی‌آید — مطمئنی؟',
        _ when expected != null => 'از ${_chosen!.bagCount} کیسه حدود $expected انتظار می‌رود',
        _ => null,
      },
      actionLabel: 'ثبت کن',
      busy: _saving,
      onAction: _count > 0 ? _save : null,
      secondary: _count > 0
          ? TextButton(
              onPressed: () => setState(() => _stage = _Stage.extras),
              child: const Text('آرد پاششی و نانینو'),
            )
          : null,
      child: OneTaskKeypad(
        value: _count,
        unit: 'چانه',
        looksWrong: _looksWrong,
        onChanged: (v) => setState(() => _count = v),
      ),
    );
  }

  /// The two numbers that are the same nearly every batch, kept off the
  /// main question and remembered between batches.
  Widget _extras() {
    final theme = Theme.of(context);

    return OneTaskScaffold(
      question: 'آرد پاششی و نانینو',
      onBack: () => setState(() => _stage = _Stage.counting),
      hint: 'اینها را به یاد می‌سپارم؛ دفعهٔ بعد لازم نیست دوباره بزنی.',
      actionLabel: 'برگرد به ثبت',
      onAction: () => setState(() => _stage = _Stage.counting),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('آرد پاششی — کیلوگرم', style: theme.textTheme.labelLarge),
          const SizedBox(height: 10),
          OneTaskCounter(
            value: _spray.round(),
            min: 0,
            max: 60,
            unit: 'کیلوگرم',
            onChanged: (v) => setState(() => _spray = v.toDouble()),
          ),
          const SizedBox(height: 26),
          Text('چانهٔ نانینو', style: theme.textTheme.labelLarge),
          const SizedBox(height: 10),
          OneTaskCounter(
            value: _nanino,
            min: 0,
            max: 999,
            unit: 'دانه',
            onChanged: (v) => setState(() => _nanino = v),
          ),
        ],
      ),
    );
  }

  Widget _done() {
    final weight = _weightKg;

    return OneTaskDone(
      headline: _queued ? 'ذخیره شد' : 'ثبت شد',
      summary: [
        '$_count چانه از ${_chosen?.bagCount ?? 0} کیسه',
        if (_queued)
          'اینترنت وصل نیست — با اتصال بعدی می‌رود'
        else if (weight != null)
          'وزن کل ${weight.toStringAsFixed(1)} کیلوگرم',
        if (_nanino > 0) '$_nanino دانه نانینو',
      ],
      actionLabel: 'دستهٔ بعدی',
      onAction: _startOver,
    );
  }
}
