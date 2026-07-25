import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/chane_board.dart';
import '../../providers/auth_provider.dart';
import '../../services/bakery_api.dart';
import '../../widgets/attendance_card.dart';
import '../../widgets/chane_comparison.dart';
import '../../widgets/common.dart';
import '../shared/settings_screen.dart';

/// The shater works the oven, so this screen answers one question at a
/// glance: how many chane are waiting. Everything else is secondary.
class ShaterHomeScreen extends StatefulWidget {
  const ShaterHomeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<ShaterHomeScreen> createState() => _ShaterHomeScreenState();
}

class _ShaterHomeScreenState extends State<ShaterHomeScreen> {
  late Future<ChaneBoard> _board;

  @override
  void initState() {
    super.initState();
    _board = widget.api.chaneBoard();
  }

  void _reload() {
    setState(() => _board = widget.api.chaneBoard());
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;

    return Scaffold(
      appBar: AppBar(
        title: const Text('شاطر'),
        actions: [
          const ThemeToggleButton(),
          IconButton(
            icon: const Icon(Icons.settings_outlined),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const SettingsScreen()),
            ),
          ),
        ],
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(
                'سلام ${user?.name ?? ''}',
                style: Theme.of(context)
                    .textTheme
                    .headlineSmall
                    ?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 20),
              AttendanceCard(api: widget.api),
              const SizedBox(height: 20),
              FutureBuilder<ChaneBoard>(
                future: _board,
                builder: (context, snapshot) {
                  if (snapshot.connectionState == ConnectionState.waiting) {
                    return const Padding(
                      padding: EdgeInsets.symmetric(vertical: 60),
                      child: Center(child: CircularProgressIndicator()),
                    );
                  }

                  if (snapshot.hasError) {
                    return ErrorBox(
                      message: '${snapshot.error}',
                      onRetry: _reload,
                    );
                  }

                  final board = snapshot.data!;

                  return Column(
                    children: [
                      _WaitingCard(board: board),
                      const SizedBox(height: 16),
                      ChaneComparison(board: board),
                      const SizedBox(height: 16),
                      _QueueCard(board: board),
                    ],
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The headline number: chane waiting for the oven.
class _WaitingCard extends StatelessWidget {
  const _WaitingCard({required this.board});

  final ChaneBoard board;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final hasWork = board.waitingChane > 0;
    final accent = hasWork ? scheme.primary : const Color(0xFF2E9E6B);

    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 28),
        child: Column(
          children: [
            Container(
              width: 76,
              height: 76,
              decoration: BoxDecoration(
                color: accent.withValues(alpha: 0.14),
                shape: BoxShape.circle,
              ),
              child: Icon(
                hasWork ? Icons.local_fire_department_rounded : Icons.check_circle_rounded,
                size: 40,
                color: accent,
              ),
            ),
            const SizedBox(height: 18),
            Text(
              'چانه در انتظار پخت',
              style: Theme.of(context).textTheme.titleMedium?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
            ),
            const SizedBox(height: 8),
            // Deliberately oversized — readable across the bakery floor.
            Text(
              '${board.waitingChane}',
              style: Theme.of(context).textTheme.displayMedium?.copyWith(
                    fontWeight: FontWeight.w900,
                    color: accent,
                  ),
            ),
            const SizedBox(height: 6),
            Text(
              hasWork
                  ? 'در ${board.waitingBatches} دسته'
                  : 'همه چانه‌ها پخته شده‌اند',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}

class _QueueCard extends StatelessWidget {
  const _QueueCard({required this.board});

  final ChaneBoard board;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Row(
          children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(
                color: const Color(0xFFE8952D).withValues(alpha: 0.14),
                borderRadius: BorderRadius.circular(14),
              ),
              child: const Icon(Icons.inventory_2_rounded,
                  color: Color(0xFFE8952D), size: 24),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'خمیر در انتظار چانه',
                    style: Theme.of(context)
                        .textTheme
                        .titleSmall
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${board.pendingDoughBags} کیسه در ${board.pendingDoughBatches} دسته',
                    style: Theme.of(context)
                        .textTheme
                        .bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
