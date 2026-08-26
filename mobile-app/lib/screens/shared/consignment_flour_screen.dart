import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

/// Flour that is out with a partner bakery, or owed to one.
///
/// The shop lends and borrows sacks constantly, and the record of it lived
/// only in the panel: the app could write a consignment and had no way to
/// read one back. Somebody standing in the store about to lend twenty more
/// sacks could not see the fifty-six already out.
///
/// Reached from «آرد همکار», because recording one and checking the others
/// is the same errand and was two different places.
class ConsignmentFlourScreen extends StatefulWidget {
  const ConsignmentFlourScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  State<ConsignmentFlourScreen> createState() => _ConsignmentFlourScreenState();
}

typedef _Data = ({
  List<Map<String, dynamic>> records,
  Map<String, dynamic> balance,
});

class _ConsignmentFlourScreenState extends State<ConsignmentFlourScreen> {
  late Future<_Data> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_Data> _load() async {
    final records = await widget.api.consignmentFlour();
    final balance = await widget.api.consignmentBalance();

    return (records: records, balance: balance);
  }

  Future<void> _refresh() async {
    final future = _load();
    setState(() => _future = future);
    await future;
  }

  Future<void> _settle(Map<String, dynamic> record) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('تسویه شد؟'),
        content: Text(
          '${record['quantity_label']} با ${record['partner_name']} تسویه شده است؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('نه'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('بله، تسویه شد'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    try {
      await widget.api.settleConsignment(record['id'] as int);
      if (!mounted) return;
      showMessage(context, 'تسویه ثبت شد.');
      await _refresh();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('آرد امانی')),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: FutureBuilder<_Data>(
          future: _future,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }

            if (snapshot.hasError) {
              final error = snapshot.error;

              return ListView(
                padding: const EdgeInsets.all(16),
                children: [
                  _Message(
                    text: error is ApiException
                        ? error.message
                        : 'فهرست آرد امانی خوانده نشد.',
                    onRetry: _refresh,
                  ),
                ],
              );
            }

            final data = snapshot.data!;

            return ListView(
              padding: const EdgeInsets.all(16),
              children: [
                _BalanceCard(balance: data.balance),
                const SizedBox(height: 16),
                if (data.records.isEmpty)
                  const _Message(text: 'هیچ آرد امانی‌ای باز نیست.')
                else
                  for (final record in data.records) ...[
                    _ConsignmentTile(
                      record: record,
                      onSettle: () => _settle(record),
                    ),
                    const SizedBox(height: 10),
                  ],
              ],
            );
          },
        ),
      ),
    );
  }
}

/// Sacks, written the way the shop says them — «۳ کیسه», not «3.0».
String _bags(double value) {
  final rounded = value == value.roundToDouble()
      ? value.toStringAsFixed(0)
      : value.toStringAsFixed(1);

  return '$rounded کیسه';
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.balance});

  final Map<String, dynamic> balance;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final lent = (balance['lent_bags'] as num?)?.toDouble() ?? 0;
    final borrowed = (balance['borrowed_bags'] as num?)?.toDouble() ?? 0;
    final net = (balance['net_bags'] as num?)?.toDouble() ?? 0;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: _Figure(
                    label: 'دست همکارها',
                    value: lent,
                    color: AppColors.moneyIn,
                  ),
                ),
                Expanded(
                  child: _Figure(
                    label: 'از همکارها گرفته‌ایم',
                    value: borrowed,
                    color: AppColors.moneyOut,
                  ),
                ),
              ],
            ),
            const Divider(height: 26),
            Text(
              // Which way round the net goes is the whole point of the
              // figure, so it is said in words rather than left to a sign
              // whose meaning the reader has to remember.
              net == 0
                  ? 'حساب آرد امانی صاف است.'
                  : net > 0
                      ? '${_bags(net)} طلب داریم.'
                      : '${_bags(net.abs())} بدهکاریم.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: scheme.onSurface,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Figure extends StatelessWidget {
  const _Figure({
    required this.label,
    required this.value,
    required this.color,
  });

  final String label;
  final double value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Column(
      children: [
        Text(
          _bags(value),
          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                fontWeight: FontWeight.w800,
                color: color,
              ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          textAlign: TextAlign.center,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: scheme.onSurfaceVariant,
              ),
        ),
      ],
    );
  }
}

class _ConsignmentTile extends StatelessWidget {
  const _ConsignmentTile({required this.record, required this.onSettle});

  final Map<String, dynamic> record;
  final VoidCallback onSettle;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final lent = record['direction'] == 'lent';
    final tone = lent ? AppColors.moneyIn : AppColors.moneyOut;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(
                  lent ? Icons.north_east_rounded : Icons.south_west_rounded,
                  size: IconSize.row,
                  color: tone,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    '${record['partner_name']}',
                    style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                          fontWeight: FontWeight.w700,
                          color: scheme.onSurface,
                        ),
                  ),
                ),
                Text(
                  '${record['quantity_label']}',
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: tone,
                      ),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: Text(
                    '${record['direction_label']}  •  ${record['occurred_on_display']}',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                  ),
                ),
                TextButton(
                  onPressed: onSettle,
                  child: const Text('تسویه شد'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Message extends StatelessWidget {
  const _Message({required this.text, this.onRetry});

  final String text;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              text,
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: onRetry == null
                        ? scheme.onSurfaceVariant
                        : scheme.error,
                  ),
            ),
            if (onRetry != null)
              TextButton(onPressed: onRetry, child: const Text('دوباره')),
          ],
        ),
      ),
    );
  }
}
