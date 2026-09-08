import 'package:flutter/material.dart';

import '../../models/bank_account.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/json.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// دنگ — how the period's profit divides, and paying it out.
///
/// The shares, the split and the settlement have been on the server and in
/// the panel since they were written. The phone had none of it, so the one
/// figure a partner actually asks about — «سهم من چقدر شد» — was the one
/// the owner could not answer without a computer.
///
/// The dividing is the server's. Each cut is rounded to the currency and
/// the residual goes to the largest holder so the parts add back up to the
/// profit exactly; repeating that arithmetic here would give «سهم من» two
/// answers, which is worse than none.
class ShareSplitSection extends StatefulWidget {
  const ShareSplitSection({
    super.key,
    required this.api,
    required this.from,
    required this.to,
  });

  final BakeryApi api;
  final String from;
  final String to;

  @override
  State<ShareSplitSection> createState() => _ShareSplitSectionState();
}

class _ShareSplitSectionState extends State<ShareSplitSection> {
  late Future<Map<String, dynamic>> _split;

  @override
  void initState() {
    super.initState();
    _split = _load();
  }

  @override
  void didUpdateWidget(ShareSplitSection old) {
    super.didUpdateWidget(old);

    if (old.from != widget.from || old.to != widget.to) {
      setState(() => _split = _load());
    }
  }

  Future<Map<String, dynamic>> _load() =>
      widget.api.profitSplit(from: widget.from, to: widget.to);

  void _reload() => setState(() => _split = _load());

  Future<void> _pay(Map<String, dynamic> holder) async {
    final accounts = await _accounts();

    if (!mounted) return;

    final result = await showModalBottomSheet<_Payment>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _PaySheet(
        name: '${holder['name'] ?? ''}',
        remainingFormatted: '${holder['remaining_formatted'] ?? '—'}',
        accounts: accounts,
      ),
    );

    if (result == null || !mounted) return;

    try {
      await widget.api.settleShare(
        (holder['id'] as num).toInt(),
        from: widget.from,
        to: widget.to,
        amount: result.amount,
        bankAccountId: result.accountId,
        note: result.note,
      );

      if (!mounted) return;

      showMessage(context, 'تسویه ثبت شد.');
      _reload();
    } on ApiException catch (e) {
      // The server refuses a period already settled, which is the one
      // failure worth reading: paying twice takes the money out twice.
      if (mounted) showMessage(context, e.message, isError: true);
    }
  }

  Future<List<BankAccount>> _accounts() async {
    try {
      return (await widget.api.bankBalances()).accounts;
    } on Object {
      // Paying without naming an account is allowed; it just does not
      // move a balance. Better than refusing the payment outright.
      return const [];
    }
  }

  void _showSettlements() {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _SettlementsSheet(api: widget.api),
    );
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<Map<String, dynamic>>(
      future: _split,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const AdminSection(
            title: 'دنگ شرکا',
            icon: Icons.pie_chart_rounded,
            children: [AdminRow(label: 'در حال بارگذاری', value: '…')],
          );
        }

        if (snapshot.hasError || snapshot.data == null) {
          return const SizedBox.shrink();
        }

        final data = snapshot.data!;
        final holders = rowList(data['holders']);

        if (holders.isEmpty) {
          // A shop with one owner has nothing to divide, and an empty
          // table would read as something missing rather than absent.
          return const SizedBox.shrink();
        }

        return AdminSection(
          title: 'دنگ شرکا',
          icon: Icons.pie_chart_rounded,
          trailing: Text(
            '${data['profit_formatted'] ?? '—'}',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: Theme.of(context).colorScheme.onSurface,
                ),
          ),
          children: [
            for (final holder in holders)
              _HolderRow(
                holder: holder,
                onPay: () => _pay(holder),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 10, 14, 14),
              child: Text(
                'سهم‌ها از سود همین بازه حساب می‌شود.'
                ' هر تسویه با مبلغ همان روز ثبت می‌ماند، پس اصلاح بعدیِ'
                ' دفترها آنچه پرداخت شده را عوض نمی‌کند.',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.history_rounded,
                  size: IconSize.row, color: AppColors.moneyIn),
              title: const Text('سابقهٔ تسویه‌ها'),
              subtitle: const Text('هرچه تا امروز بابت دنگ پرداخت شده'),
              trailing: const Icon(Icons.chevron_left_rounded),
              onTap: _showSettlements,
            ),
          ],
        );
      },
    );
  }
}

class _HolderRow extends StatelessWidget {
  const _HolderRow({required this.holder, required this.onPay});

  final Map<String, dynamic> holder;
  final VoidCallback onPay;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final remaining = (holder['remaining'] as num?)?.toDouble() ?? 0;
    final paid = (holder['paid'] as num?)?.toDouble() ?? 0;
    final settled = remaining <= 0 && paid > 0;

    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  personName({'name': holder['name']},
                      fallbackId: holder['id']),
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                        color: scheme.onSurface,
                      ),
                ),
                Text(
                  '${holder['dang_label'] ?? ''}'
                  '  •  ${holder['amount_formatted'] ?? '—'}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                if (paid > 0)
                  Text(
                    settled
                        ? 'تسویه شده'
                        : 'پرداخت‌شده ${holder['paid_formatted']}'
                            ' — مانده ${holder['remaining_formatted']}',
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: settled
                              ? AppColors.moneyIn
                              : AppColors.attention,
                        ),
                  ),
              ],
            ),
          ),
          if (settled)
            const Icon(Icons.check_circle_rounded,
                color: AppColors.moneyIn, size: IconSize.button)
          else
            TextButton(onPressed: onPay, child: const Text('پرداخت')),
        ],
      ),
    );
  }
}

typedef _Payment = ({double? amount, int? accountId, String? note});

class _PaySheet extends StatefulWidget {
  const _PaySheet({
    required this.name,
    required this.remainingFormatted,
    required this.accounts,
  });

  final String name;
  final String remainingFormatted;
  final List<BankAccount> accounts;

  @override
  State<_PaySheet> createState() => _PaySheetState();
}

class _PaySheetState extends State<_PaySheet> {
  final _amount = TextEditingController();
  final _note = TextEditingController();
  int? _accountId;

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        20,
        20,
        MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'پرداخت دنگ ${widget.name}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 6),
          Text(
            'مانده: ${widget.remainingFormatted}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _amount,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'مبلغ (خالی = تمام سهم این بازه)',
              prefixIcon: Icon(Icons.payments_rounded),
            ),
          ),
          if (widget.accounts.isNotEmpty) ...[
            const SizedBox(height: 12),
            DropdownButtonFormField<int>(
              initialValue: _accountId,
              isExpanded: true,
              decoration: const InputDecoration(
                labelText: 'از کدام حساب',
                prefixIcon: Icon(Icons.account_balance_rounded),
              ),
              items: [
                const DropdownMenuItem(value: null, child: Text('—')),
                for (final account in widget.accounts)
                  DropdownMenuItem(
                    value: account.id,
                    child: Text(account.title),
                  ),
              ],
              onChanged: (v) => setState(() => _accountId = v),
            ),
          ],
          const SizedBox(height: 12),
          TextField(
            controller: _note,
            maxLength: 500,
            decoration: const InputDecoration(
              labelText: 'یادداشت (اختیاری)',
              prefixIcon: Icon(Icons.notes_rounded),
            ),
          ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed: () {
              final typed = _amount.text.trim();
              final amount = typed.isEmpty ? null : double.tryParse(typed);

              // A number that will not parse is not the same as leaving
              // the field empty, and treating it as «all of it» would pay
              // out a figure nobody typed.
              if (typed.isNotEmpty && amount == null) {
                showMessage(context, 'مبلغ را درست وارد کنید.', isError: true);

                return;
              }

              Navigator.pop(context, (
                amount: amount,
                accountId: _accountId,
                note: _note.text.trim().isEmpty ? null : _note.text.trim(),
              ));
            },
            child: const Text('ثبت پرداخت'),
          ),
        ],
      ),
    );
  }
}


/// What has actually been handed over, period by period.
///
/// The split above answers «سهم من چقدر می‌شود» for one stretch. This
/// answers «چقدر گرفته‌ام», which is the question that comes back a year
/// later when nobody remembers and the paper is gone.
class _SettlementsSheet extends StatefulWidget {
  const _SettlementsSheet({required this.api});

  final BakeryApi api;

  @override
  State<_SettlementsSheet> createState() => _SettlementsSheetState();
}

class _SettlementsSheetState extends State<_SettlementsSheet> {
  late final Future<List<Map<String, dynamic>>> _rows =
      widget.api.shareSettlements();

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 20, 20, 12),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'سابقهٔ تسویهٔ دنگ',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            const SizedBox(height: 12),
            FutureBuilder<List<Map<String, dynamic>>>(
              future: _rows,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 28),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }

                if (snapshot.hasError) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    child: Text(
                      'سابقه خوانده نشد.',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  );
                }

                final rows = snapshot.data ?? const <Map<String, dynamic>>[];

                if (rows.isEmpty) {
                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 24),
                    child: Text(
                      'هنوز دنگی پرداخت نشده است.',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                  );
                }

                return ConstrainedBox(
                  constraints: BoxConstraints(
                    maxHeight: MediaQuery.of(context).size.height * 0.6,
                  ),
                  child: ListView.separated(
                    shrinkWrap: true,
                    itemCount: rows.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (_, i) => _SettlementRow(row: rows[i]),
                  ),
                );
              },
            ),
          ],
        ),
      ),
    );
  }
}

class _SettlementRow extends StatelessWidget {
  const _SettlementRow({required this.row});

  final Map<String, dynamic> row;

  @override
  Widget build(BuildContext context) {
    final share = keyedGroup(row['share']);
    final paid = row['is_paid'] == true;
    final note = '${row['note'] ?? ''}'.trim();

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  personName(
                    {'name': share['name']},
                    fallbackId: row['bakery_share_id'],
                  ),
                  style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                ),
                Text(
                  '${row['period_label'] ?? '—'}'
                  '${paid ? '  •  ${row['paid_on_display'] ?? ''}' : '  •  پرداخت نشده'}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                if (note.isNotEmpty)
                  Text(note, style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ),
          Text(
            '${row['amount_formatted'] ?? '—'}',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: paid ? AppColors.moneyIn : AppColors.attention,
                ),
          ),
        ],
      ),
    );
  }
}
