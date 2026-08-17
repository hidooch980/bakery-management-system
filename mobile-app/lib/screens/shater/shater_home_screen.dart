import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/chane_board.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/chane_comparison.dart';
import '../../widgets/common.dart';
import '../shared/me_screen.dart';
import '../shared/settings_screen.dart';

/// The shater records nothing. He wants one number.
///
/// «چقدر چانه منتظر تنور است؟» — and until now the answer to that was the
/// fourth thing down a scrolling page, under a greeting, his attendance
/// and his own pay. The other roles ask one question a screen; this role
/// is asked nothing, so it is given one answer a screen instead, in the
/// same shape and the same size of figure.
///
/// What is behind it — the batches making up the total, the nanino
/// comparison the flour quota follows — is below, for when he wants it.
/// Attendance and pay moved behind «حساب من» in the bar with everyone
/// else's.
class ShaterHomeScreen extends StatefulWidget {
  const ShaterHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<ShaterHomeScreen> createState() => _ShaterHomeScreenState();
}

class _ShaterHomeScreenState extends State<ShaterHomeScreen> {
  late Future<ChaneBoard> _board;

  Bakery? _bakery;

  @override
  void initState() {
    super.initState();
    _board = widget.api.chaneBoard();
    _loadBakery();
  }

  Future<void> _loadBakery() async {
    try {
      final bakery = await widget.api.bakery();
      if (mounted) setState(() => _bakery = bakery);
    } on ApiException {
      // The figure reads fine without the shop's name over it.
    }
  }

  Future<void> _reload() async {
    setState(() => _board = widget.api.chaneBoard());
    await _board;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_bakery?.name ?? 'شاطر'),
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
      body: RefreshIndicator(
        onRefresh: _reload,
        child: FutureBuilder<ChaneBoard>(
          future: _board,
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

            return _waiting(snapshot.data!);
          },
        ),
      ),
    );
  }

  Widget _waiting(ChaneBoard board) {
    final theme = Theme.of(context);
    final waiting = board.waitingChane;

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
      children: [
        Text(
          'منتظر تنور',
          style: theme.textTheme.titleMedium?.copyWith(
            color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
          ),
        ),
        const SizedBox(height: 4),
        Row(
          textBaseline: TextBaseline.alphabetic,
          crossAxisAlignment: CrossAxisAlignment.baseline,
          children: [
            Text(
              '$waiting',
              style: theme.textTheme.displayLarge?.copyWith(
                fontSize: 92,
                height: 1.05,
                fontWeight: FontWeight.w700,
                letterSpacing: -3,
                fontFeatures: const [FontFeature.tabularFigures()],
              ),
            ),
            const SizedBox(width: 10),
            Text('چانه', style: theme.textTheme.titleLarge),
          ],
        ),
        Text(
          waiting == 0
              ? 'چیزی در صف نیست'
              : 'در ${board.waitingBatches} دسته'
                  '${board.pendingDoughBatches > 0 ? '، و ${board.pendingDoughBags} کیسه خمیر هنوز چانه نشده' : ''}',
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurface.withValues(alpha: 0.6),
          ),
        ),
        const SizedBox(height: 26),
        ChaneComparison(board: board),
        const SizedBox(height: 16),
        _Today(board: board),
      ],
    );
  }
}

/// What the day has produced so far — read after the figure that mattered,
/// not before it.
class _Today extends StatelessWidget {
  const _Today({required this.board});

  final ChaneBoard board;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'امروز — ${board.dateDisplay}',
              style: theme.textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 12),
            _Row(label: 'چانهٔ عادی', value: '${board.normalCount}'),
            _Row(label: 'چانهٔ نانینو', value: '${board.naninoCount}'),
            _Row(
              label: 'وزن کل',
              value: '${board.totalWeightKg.toStringAsFixed(1)} کیلوگرم',
            ),
            _Row(label: 'کیسهٔ خمیر امروز', value: '${board.doughBagsToday}'),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: theme.colorScheme.onSurface.withValues(alpha: 0.65),
            ),
          ),
          Text(
            value,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w700,
              fontFeatures: const [FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}
