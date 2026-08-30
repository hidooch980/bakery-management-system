import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/entries.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../services/last_used.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import '../../widgets/one_task.dart';
import '../shared/me_screen.dart';
import '../shared/settings_screen.dart';

/// The dough maker's whole app: one question.
///
/// This role records one number a day and nothing else. It used to get the
/// seller's shape — a scrolling page with a history list, an attendance
/// card, a pay card, a menu and a floating button that opened a sheet with
/// three fields in it. Six places to tap, to enter one number.
///
/// Now the app asks «چند کیسه خمیر گرفتی؟», the answer is the size of a
/// fist, and there is one button. Everything else — what was recorded
/// today, the batches waiting to be shaped — is shown after the answer, on
/// the way out, where it is news rather than clutter.
class DoughHomeScreen extends StatefulWidget {
  const DoughHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<DoughHomeScreen> createState() => _DoughHomeScreenState();
}

enum _Stage { asking, done }

class _DoughHomeScreenState extends State<DoughHomeScreen> {
  _Stage _stage = _Stage.asking;

  /// Null until the phone has been asked what was used last time, which
  /// keeps the screen from flashing a wrong number first.
  int? _bags;

  bool _saving = false;
  bool _queued = false;

  /// Set when the server refuses a repeat; cleared by either answer.
  String? _repeat;

  Bakery? _bakery;
  List<DoughEntry> _today = const [];

  @override
  void initState() {
    super.initState();
    _prepare();
  }

  Future<void> _prepare() async {
    final bags = await LastUsed.doughBags();

    if (!mounted) return;
    setState(() => _bags = bags);

    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // The question reads fine without the shop's name on it.
    }

    await _loadToday();
  }

  Future<void> _loadToday() async {
    try {
      final history = await widget.api.myDoughHistory();
      final now = DateTime.now();

      if (!mounted) return;
      setState(() => _today = history.where((e) {
            final at = e.createdAt;

            // A batch the server has not dated yet is one this phone has
            // only just queued, so it belongs to today by definition.
            return at == null ||
                (at.year == now.year && at.month == now.month && at.day == now.day);
          }).toList());
    } on ApiException {
      // Not worth an error of its own: it is the footnote, not the answer.
    }
  }

  Future<void> _save({bool force = false}) async {
    final bags = _bags;

    if (bags == null || _saving) return;

    setState(() {
      _saving = true;
      if (force) _repeat = null;
    });

    try {
      final queued = await widget.api.recordDough(bagCount: bags, force: force);

      await LastUsed.rememberDoughBags(bags);
      await _loadToday();

      if (!mounted) return;
      setState(() {
        _queued = queued;
        _stage = _Stage.done;
      });
    } on ApiException catch (e) {
      if (!mounted) return;

      // 409 is the double-tap guard, and it is a question rather than a
      // failure — the same thirteen bags twice in ten minutes is usually
      // one batch recorded twice, but not always.
      if (e.statusCode == 409) {
        setState(() => _repeat = e.message);
      } else {
        showMessage(context, e.message, isError: true);
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _again() {
    setState(() {
      _stage = _Stage.asking;
      _repeat = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_bakery?.name ?? 'خمیرگیری'),
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
              MaterialPageRoute(builder: (_) => const SettingsScreen()),
            ),
          ),
        ],
      ),
      body: switch (_stage) {
        _Stage.asking => _ask(),
        _Stage.done => _done(),
      },
    );
  }

  Widget _ask() {
    final bags = _bags;

    if (bags == null) {
      return const Center(child: CircularProgressIndicator());
    }

    return OneTaskScaffold(
      question: 'چند کیسه خمیر گرفتی؟',
      hint: _today.isEmpty
          ? 'دفعهٔ پیش $bags کیسه بود'
          : 'امروز ${_today.length} بار ثبت کرده‌ای — '
              '${_today.fold<int>(0, (sum, e) => sum + e.bagCount)} کیسه '
              '(ساعت ${_today.map((e) => JalaliFormat.time(e.createdAt)).join('، ')})',
      actionLabel: 'ثبت کن',
      busy: _saving,
      onAction: _repeat == null ? _save : null,
      child: _repeat != null
          ? OneTaskRepeatWarning(
              message: _repeat!,
              onCancel: () => setState(() => _repeat = null),
              onConfirm: () => _save(force: true),
            )
          : OneTaskCounter(
              value: bags,
              unit: 'کیسه',
              onChanged: (v) => setState(() => _bags = v),
            ),
    );
  }

  Widget _done() {
    final bags = _bags ?? 0;

    return OneTaskDone(
      headline: _queued ? 'ذخیره شد' : 'ثبت شد',
      summary: [
        '$bags کیسه خمیر',
        if (_queued)
          'اینترنت وصل نیست — با اتصال بعدی می‌رود'
        else
          '${JalaliFormat.date(DateTime.now())} — ساعت ${JalaliFormat.time(DateTime.now())}',
        if (_today.length > 1) 'امروز روی هم ${_today.fold<int>(0, (s, e) => s + e.bagCount)} کیسه',
      ],
      actionLabel: 'یک دستهٔ دیگر',
      onAction: _again,
    );
  }
}
