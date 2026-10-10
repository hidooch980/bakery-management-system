import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import 'partner_statement_screen.dart';

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
  List<Map<String, dynamic>> partners,
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
    final partners = await widget.api.consignmentPartners();
    final balance = await widget.api.consignmentBalance();

    return (records: records, partners: partners, balance: balance);
  }

  Future<void> _refresh() async {
    final future = _load();
    setState(() => _future = future);
    await future;
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

                // Who has them, before what happened. Standing in the
                // store the question is «چقدر دست کیست», and reading it
                // off a list of individual entries is arithmetic done in
                // the head, at the moment of deciding to lend more.
                // «همه اسما باشه»: همهٔ همکاران، حتی آن‌که حسابش صاف است.
                const _SectionTitle('به تفکیک همکار'),
                const SizedBox(height: 8),
                if (data.partners.isEmpty)
                  const _Message(text: 'هنوز همکاری تعریف نشده است.'),
                for (final partner in data.partners) ...[
                  _PartnerTile(
                    partner: partner,
                    // نام همکار پروندهٔ او را باز می‌کند (گردش ریز به کیسه).
                    onTap: partner['partner_id'] is int
                        ? () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => PartnerStatementScreen(
                                  api: widget.api,
                                  partnerId: partner['partner_id'] as int,
                                  partnerName: '${partner['partner_name']}',
                                ),
                              ),
                            )
                        : null,
                  ),
                  const SizedBox(height: 8),
                ],
                const SizedBox(height: 14),
                // هر ثبت فقط یک جابه‌جایی است. تسویه‌ای در کار نیست:
                // ثبتِ طرف مقابل خودش از مانده کم می‌شود.
                const _SectionTitle('ثبت‌ها'),
                const SizedBox(height: 8),
                if (data.records.isEmpty)
                  const _Message(text: 'هنوز آرد امانی‌ای ثبت نشده است.')
                else
                  for (final record in data.records) ...[
                    _ConsignmentTile(record: record),
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

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: Theme.of(context).textTheme.titleSmall?.copyWith(
            fontWeight: FontWeight.w800,
            color: Theme.of(context).colorScheme.onSurfaceVariant,
          ),
    );
  }
}

/// One partner's whole account on one line: how much of it is out, and
/// how long the oldest of it has been.
class _PartnerTile extends StatelessWidget {
  const _PartnerTile({required this.partner, this.onTap});

  final Map<String, dynamic> partner;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final net = (partner['net_bags'] as num?)?.toDouble() ?? 0;
    final days = partner['days'] as int?;
    final entries = (partner['entries'] as num?)?.toInt() ?? 0;
    final owed = net > 0.001;
    final square = net.abs() <= 0.001;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${partner['partner_name']}',
                      style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                            fontWeight: FontWeight.w700,
                            color: scheme.onSurface,
                          ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      // Days, because that is how the shop talks about it —
                      // «۵۶ کیسه، ۲۳ روز» — and because a date makes the
                      // reader do the subtraction.
                      entries == 0
                          ? 'هنوز ثبتی ندارد'
                          : days == null
                              ? '$entries ثبت'
                              : days == 0
                                  ? 'از امروز  •  $entries ثبت'
                                  : '$days روز  •  $entries ثبت',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(
                    _bags(net.abs()),
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: square
                              ? scheme.onSurfaceVariant
                              : owed
                                  ? AppColors.moneyIn
                                  : AppColors.moneyOut,
                        ),
                  ),
                  Text(
                    square
                        ? 'تسویه'
                        : owed
                            ? 'طلب ما'
                            : 'بدهی ما',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                  ),
                ],
              ),
              if (onTap != null) ...[
                const SizedBox(width: 6),
                Icon(
                  Icons.chevron_left_rounded,
                  size: IconSize.row,
                  color: scheme.onSurfaceVariant,
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _ConsignmentTile extends StatelessWidget {
  const _ConsignmentTile({required this.record});

  final Map<String, dynamic> record;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final lent = record['direction'] == 'lent';
    final tone = lent ? AppColors.moneyIn : AppColors.moneyOut;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
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
            Text(
              '${record['direction_label']}  •  ${record['occurred_on_display']}'
              '${(record['note'] ?? '').toString().isNotEmpty ? '  •  ${record['note']}' : ''}',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
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
