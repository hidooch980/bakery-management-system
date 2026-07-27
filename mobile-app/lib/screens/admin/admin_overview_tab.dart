import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/chane_board.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/chane_comparison.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';
import 'admin_record_sheet.dart';

/// Today at a glance: production, sales, attendance and the work queues.
class AdminOverviewTab extends StatefulWidget {
  const AdminOverviewTab({super.key, required this.api, this.bakery});

  final BakeryApi api;
  final Bakery? bakery;

  @override
  State<AdminOverviewTab> createState() => _AdminOverviewTabState();
}

class _AdminOverviewTabState extends State<AdminOverviewTab> {
  late Future<({Map<String, dynamic> dashboard, ChaneBoard board})> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<({Map<String, dynamic> dashboard, ChaneBoard board})> _load() async {
    // Both calls are independent, so run them together.
    final results = await Future.wait([
      widget.api.dashboard(),
      widget.api.chaneBoard(),
    ]);

    return (
      dashboard: results[0] as Map<String, dynamic>,
      board: results[1] as ChaneBoard,
    );
  }

  void _reload() => setState(() => _data = _load());

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<({Map<String, dynamic> dashboard, ChaneBoard board})>(
        future: _data,
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

          final dashboard = snapshot.data!.dashboard;
          final board = snapshot.data!.board;

          final today = dashboard['today'] as Map<String, dynamic>? ?? const {};
          final queues = dashboard['queues'] as Map<String, dynamic>? ?? const {};
          final staff = dashboard['staff'] as Map<String, dynamic>? ?? const {};

          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            children: [
              Text(
                JalaliFormat.longDate(DateTime.now()),
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: Theme.of(context).colorScheme.onSurfaceVariant,
                    ),
              ),
              const SizedBox(height: 16),

              // The four things an admin records away from a desk, so the
              // web panel is not needed to keep the books straight.
              _QuickRecordRow(api: widget.api, bakery: widget.bakery, onSaved: _reload),
              const SizedBox(height: 18),

              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'کیسه خمیر',
                      value: '${_num(today['dough_bags'])}',
                      icon: Icons.inventory_2_rounded,
                      color: const Color(0xFFE8952D),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: StatTile(
                      label: 'چانه',
                      value: '${_num(today['chane_count'])}',
                      icon: Icons.grain_rounded,
                      color: const Color(0xFF3B82C4),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'فروش امروز',
                      value: adminMoney(widget.bakery, _num(today['sales_amount'])),
                      icon: Icons.payments_rounded,
                      color: const Color(0xFF2E9E6B),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: StatTile(
                      label: 'حضور',
                      value: '${_num(today['attendance_count'])} نفر',
                      icon: Icons.how_to_reg_rounded,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                  ),
                ],
              ),

              const SizedBox(height: 22),
              ChaneComparison(board: board),

              const SizedBox(height: 22),
              AdminSection(
                title: 'صف کاری',
                icon: Icons.queue_rounded,
                children: [
                  AdminRow(
                    label: 'خمیر در انتظار چانه',
                    value: '${_num(queues['pending_dough'])} دسته',
                    icon: Icons.pending_actions_rounded,
                  ),
                  const Divider(height: 1),
                  AdminRow(
                    label: 'چانه در انتظار فروش',
                    value: '${_num(queues['pending_chane'])} دسته',
                    icon: Icons.storefront_rounded,
                  ),
                  const Divider(height: 1),
                  AdminRow(
                    label: 'چانه در انتظار پخت',
                    value: '${board.waitingChane} عدد',
                    icon: Icons.local_fire_department_rounded,
                  ),
                ],
              ),

              const SizedBox(height: 22),
              AdminSection(
                title: 'کارکنان',
                icon: Icons.people_rounded,
                children: [
                  AdminRow(
                    label: 'کل کارکنان',
                    value: '${_num(staff['total'])} نفر',
                    icon: Icons.groups_rounded,
                  ),
                  const Divider(height: 1),
                  AdminRow(
                    label: 'فعال',
                    value: '${_num(staff['active'])} نفر',
                    icon: Icons.check_circle_outline_rounded,
                    color: const Color(0xFF2E9E6B),
                  ),
                ],
              ),
            ],
          );
        },
      ),
    );
  }

  static num _num(dynamic value) {
    if (value is num) return value;

    return num.tryParse('$value') ?? 0;
  }
}


/// Shortcut buttons onto the record sheet, one per thing the admin logs.
class _QuickRecordRow extends StatelessWidget {
  const _QuickRecordRow({
    required this.api,
    required this.bakery,
    required this.onSaved,
  });

  final BakeryApi api;
  final Bakery? bakery;
  final VoidCallback onSaved;

  Future<void> _open(BuildContext context, AdminRecordKind kind) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => AdminRecordSheet(api: api, kind: kind, bakery: bakery),
    );

    if (saved == true) onSaved();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'ثبت سریع',
          style: Theme.of(context)
              .textTheme
              .titleSmall
              ?.copyWith(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 10),
        // Two per row: four across is too narrow to read on a phone.
        for (var i = 0; i < AdminRecordKind.values.length; i += 2)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              children: [
                for (final kind in AdminRecordKind.values.skip(i).take(2)) ...[
                  Expanded(
                    child: _QuickRecordButton(
                      kind: kind,
                      onTap: () => _open(context, kind),
                    ),
                  ),
                  if (kind != AdminRecordKind.values.skip(i).take(2).last)
                    const SizedBox(width: 8),
                ],
              ],
            ),
          ),
      ],
    );
  }
}

class _QuickRecordButton extends StatelessWidget {
  const _QuickRecordButton({required this.kind, required this.onTap});

  final AdminRecordKind kind;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
        decoration: BoxDecoration(
          color: kind.color.withValues(alpha: 0.10),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: kind.color.withValues(alpha: 0.30)),
        ),
        child: Row(
          children: [
            Icon(kind.icon, size: 20, color: kind.color),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                kind.label,
                style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w700,
                      color: kind.color,
                    ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
