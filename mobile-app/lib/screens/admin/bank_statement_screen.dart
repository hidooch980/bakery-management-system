import 'package:flutter/material.dart';

import '../../models/bank_account.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';
import '../../theme/app_theme.dart';

/// What has moved through one account.
///
/// The balance on the finance page answers "how much is there"; this
/// answers "where did it go" — which is the question asked when the figure
/// is not what the admin expected.
class BankStatementScreen extends StatefulWidget {
  const BankStatementScreen({
    super.key,
    required this.api,
    required this.account,
  });

  final BakeryApi api;
  final BankAccount account;

  @override
  State<BankStatementScreen> createState() => _BankStatementScreenState();
}

class _BankStatementScreenState extends State<BankStatementScreen> {
  late Future<BankStatement> _statement;

  @override
  void initState() {
    super.initState();
    _statement = widget.api.bankStatement(widget.account.id);
  }

  void _reload() {
    setState(() => _statement = widget.api.bankStatement(widget.account.id));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(widget.account.label ?? widget.account.title)),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: () async => _reload(),
          child: FutureBuilder<BankStatement>(
            future: _statement,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting) {
                return const Center(child: CircularProgressIndicator());
              }

              if (snapshot.hasError) {
                final message = snapshot.error is ApiException
                    ? '${snapshot.error}'
                    : 'گردش حساب خوانده نشد.';

                return ListView(
                  padding: const EdgeInsets.all(20),
                  children: [ErrorBox(message: message, onRetry: _reload)],
                );
              }

              final statement = snapshot.data!;
              final moves = statement.transactions;

              return ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
                children: [
                  _BalanceHeader(account: statement.account),
                  const SizedBox(height: 18),

                  if (moves.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 40),
                      child: Center(
                        child: Text('هنوز گردشی روی این حساب ثبت نشده است.'),
                      ),
                    )
                  else
                    for (final move in moves) ...[
                      _MoveTile(move: move),
                      const SizedBox(height: 8),
                    ],

                  // The server answers with the most recent three hundred;
                  // saying so beats letting the list end without explanation.
                  if (moves.length >= 300)
                    const Padding(
                      padding: EdgeInsets.only(top: 12),
                      child: Text(
                        'فقط ۳۰۰ گردش اخیر نشان داده می‌شود.',
                        textAlign: TextAlign.center,
                      ),
                    ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}

class _BalanceHeader extends StatelessWidget {
  const _BalanceHeader({required this.account});

  final BankAccount account;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final accent = account.isOverdrawn
        ? AppColors.moneyOut
        : AppColors.moneyIn;

    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
        child: Column(
          children: [
            Text(
              'موجودی فعلی',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
            ),
            const SizedBox(height: 8),
            Text(
              account.balanceFormatted,
              style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w900,
                    color: accent,
                  ),
            ),
            if (account.bankName != null) ...[
              const SizedBox(height: 6),
              Text(
                account.bankName!,
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _MoveTile extends StatelessWidget {
  const _MoveTile({required this.move});

  final BankTransaction move;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    // In and out are told apart by colour and arrow rather than by a minus
    // sign, which is easy to miss on a phone held at arm's length.
    final accent = move.isIncoming
        ? AppColors.moneyIn
        : AppColors.moneyOut;

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: accent.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Icon(
                move.isIncoming
                    ? Icons.south_west_rounded
                    : Icons.north_east_rounded,
                color: accent,
                size: IconSize.button,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    move.reasonLabel,
                    style: Theme.of(context)
                        .textTheme
                        .bodyMedium
                        ?.copyWith(fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    [
                      if (move.dateDisplay != null) move.dateDisplay!,
                      if (move.user != null) move.user!,
                    ].join('  •  '),
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                  ),
                  if (move.note != null && move.note!.trim().isNotEmpty) ...[
                    const SizedBox(height: 3),
                    Text(
                      move.note!,
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                    ),
                  ],
                ],
              ),
            ),
            Text(
              move.amountFormatted,
              style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: accent,
                  ),
            ),
          ],
        ),
      ),
    );
  }
}
