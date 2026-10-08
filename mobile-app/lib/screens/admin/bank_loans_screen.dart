import 'package:flutter/material.dart';
import '../../services/bakery_api.dart';
import '../../utils/json.dart';
import '../../widgets/common.dart';
import 'financial_payment_sheet.dart';

/// اقساط بانکی نانوایی و سابقه پرداخت هر وام.
class BankLoansScreen extends StatefulWidget {
  const BankLoansScreen({super.key, required this.api});
  final BakeryApi api;
  @override
  State<BankLoansScreen> createState() => _BankLoansScreenState();
}

class _BankLoansScreenState extends State<BankLoansScreen> {
  late Future<List<Map<String, dynamic>>> _future;
  @override
  void initState() {
    super.initState();
    _future = widget.api.bankLoans();
  }

  void _reload() => setState(() => _future = widget.api.bankLoans());
  Future<void> _pay(Map<String, dynamic> loan) async {
    try {
      final balances = await widget.api.bankBalances();
      if (!mounted) return;
      final remaining = double.tryParse('${loan['remaining']}') ?? 0;
      final each = double.tryParse('${loan['instalment_amount']}') ?? 0;
      final saved = await showModalBottomSheet<bool>(
          context: context,
          isScrollControlled: true,
          builder: (_) => FinancialPaymentSheet(
              api: widget.api,
              title: 'پرداخت قسط ${loan['title']}',
              loanId: (loan['id'] as num).toInt(),
              maximumAmount: remaining,
              suggestedAmount: each > remaining ? remaining : each,
              accounts: balances.accounts.where((a) => a.isActive).toList()));
      if (saved == true && mounted) {
        showMessage(context, 'قسط و گردش حساب ثبت شد.');
        _reload();
      }
    } catch (e) {
      if (mounted) showMessage(context, '$e', isError: true);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('اقساط وام بانکی'), actions: [
          IconButton(onPressed: _reload, icon: const Icon(Icons.refresh))
        ]),
        body: FutureBuilder<List<Map<String, dynamic>>>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
              }
              final loans = snapshot.data!;
              if (loans.isEmpty) {
                return const Center(
                    child: Text('وام بانکی ثبت‌شده‌ای وجود ندارد.'));
              }
              return RefreshIndicator(
                  onRefresh: () async {
                    _reload();
                    await _future;
                  },
                  child: ListView(
                      physics: const AlwaysScrollableScrollPhysics(),
                      padding: const EdgeInsets.all(16),
                      children: [
                        const Text(
                            'وام‌های ثبت‌شده در پنل؛ ریز هر وام ۲۰ پرداخت اخیر را نشان می‌دهد.'),
                        for (final loan in loans)
                          Card(
                              child: ExpansionTile(
                                  title: Text('${loan['title']}'),
                                  subtitle: Text(
                                      '${loan['lender'] ?? ''} • مانده: ${loan['remaining_formatted']}'),
                                  children: [
                                ListTile(
                                    title: Text(
                                        'پرداخت‌شده: ${loan['paid_formatted']}'),
                                    subtitle: Text(
                                        'قسط: ${loan['instalment_formatted']} • سررسید: ${loan['next_due_on'] ?? '—'}${loan['is_overdue'] == true ? ' • عقب‌افتاده' : ''}')),
                                if (loan['can_pay'] == true)
                                  FilledButton.icon(
                                      onPressed: () => _pay(loan),
                                      icon: const Icon(Icons.payments_outlined),
                                      label: const Text('ثبت پرداخت قسط')),
                                for (final payment in rowList(loan['payments']))
                                  ListTile(
                                      title: Text(
                                          '${payment['amount_formatted']} • ${payment['date']}'),
                                      subtitle: Text(
                                          '${payment['account'] ?? ''}${payment['note'] == null ? '' : ' • ${payment['note']}'}')),
                              ])),
                      ]));
            }),
      );
}
