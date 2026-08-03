import 'package:flutter/material.dart';

import '../models/entries.dart';
import '../models/seller_account.dart';
import '../models/settlement_request.dart';
import '../services/api_client.dart';
import 'common.dart';
import '../services/bakery_api.dart';

/// What the seller still answers for, shown to them rather than only to the
/// admin — so a shortfall or an uncollected debt is something they can act
/// on the same day instead of hearing about at the end of the month.
///
/// There is no settle button here on purpose: clearing your own debt is the
/// admin's call, not the debtor's.
class SellerAccountCard extends StatefulWidget {
  const SellerAccountCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerAccountCard> createState() => _SellerAccountCardState();
}

class _SellerAccountCardState extends State<SellerAccountCard> {
  SellerAccount? _account;
  SettlementRequest? _pending;
  SettlementRequest? _lastRejected;
  bool _expanded = false;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final account = await widget.api.myAccount();
      final requests = await widget.api.settlementRequests();

      if (!mounted) return;

      setState(() {
        _account = account;
        _pending = requests.pending;
        // A rejection is worth showing until the seller acts on it, so
        // they know why the account did not clear.
        _lastRejected = requests.history
            .where((r) => r.isRejected)
            .cast<SettlementRequest?>()
            .firstWhere((r) => true, orElse: () => null);
      });
    } on ApiException {
      // The rest of the day's work does not depend on this card.
    }
  }

  Future<void> _requestSettlement() async {
    // Cash and card clear the same debt but land in different places, so
    // the seller says how the handover was split before it is sent.
    final split = await showDialog<Map<PaymentType, double>>(
      context: context,
      builder: (_) => _SettlementSplitDialog(account: _account!),
    );

    if (split == null || !mounted) return;

    setState(() => _sending = true);

    try {
      await widget.api.requestSettlement(payments: split);

      if (!mounted) return;
      showMessage(context, 'درخواست تسویه ثبت شد و در انتظار تأیید مدیر است.');
      await _load();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final account = _account;

    // Nothing owed is worth saying nothing about — the card stays away.
    if (account == null || (account.isClear && _pending == null)) {
      return const SizedBox.shrink();
    }

    final scheme = Theme.of(context).colorScheme;
    const warn = Color(0xFFE8952D);

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: warn.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: warn.withValues(alpha: 0.35)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.account_balance_wallet_rounded,
                  size: 20, color: warn),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'حساب موقت شما',
                  style: Theme.of(context)
                      .textTheme
                      .titleSmall
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
              ),
              Text(
                account.totalFormatted,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                      fontWeight: FontWeight.w800,
                      color: warn,
                    ),
              ),
            ],
          ),

          const SizedBox(height: 10),
          if (account.cash > 0)
            _AccountLine(
              icon: Icons.payments_rounded,
              label: 'پول نقد نزد شما',
              value: account.cashFormatted,
            ),
          if (account.hasDifference)
            _AccountLine(
              icon: Icons.error_outline_rounded,
              label: 'اختلاف مالی',
              value: account.differenceFormatted,
              isProblem: true,
            ),
          if (account.hasShortfall)
            _AccountLine(
              icon: Icons.remove_shopping_cart_rounded,
              label: 'کسری ${account.shortfallCount} نان',
              value: account.shortfallFormatted,
              isProblem: true,
            ),
          if (account.hasCredit)
            _AccountLine(
              icon: Icons.schedule_rounded,
              label: 'نسیه وصول‌نشده',
              value: account.creditFormatted,
            ),

          if (account.creditSales.isNotEmpty) ...[
            const SizedBox(height: 6),
            InkWell(
              onTap: () => setState(() => _expanded = !_expanded),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      _expanded ? 'بستن فهرست نسیه' : 'فهرست نسیه‌ها',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.primary,
                            fontWeight: FontWeight.w700,
                          ),
                    ),
                    Icon(
                      _expanded
                          ? Icons.keyboard_arrow_up_rounded
                          : Icons.keyboard_arrow_down_rounded,
                      size: 18,
                      color: scheme.primary,
                    ),
                  ],
                ),
              ),
            ),
            if (_expanded)
              for (final sale in account.creditSales)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          '${sale.customer ?? 'بدون مشتری'}'
                          '  •  ${sale.breadCount} نان',
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                      Text(
                        sale.amountFormatted,
                        style: Theme.of(context)
                            .textTheme
                            .bodySmall
                            ?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                ),
          ],

          const SizedBox(height: 8),
          if (_pending != null)
            _PendingNotice(request: _pending!)
          else ...[
            if (_lastRejected?.rejectionReason != null) ...[
              _RejectionNotice(reason: _lastRejected!.rejectionReason!),
              const SizedBox(height: 8),
            ],
            FilledButton.icon(
              // Credit is the customer's to pay, so it is not part of what
              // the seller can hand over — the server refuses a request
              // that would only cover that.
              onPressed: _sending ? null : _requestSettlement,
              icon: _sending
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.handshake_rounded, size: 18),
              label: Text(_sending ? 'در حال ارسال…' : 'درخواست تسویه حساب'),
            ),
            const SizedBox(height: 6),
            Text(
              'پس از تأیید مدیر، حساب شما تسویه می‌شود.'
              '\nنسیه‌ها با پرداخت مشتری تسویه می‌شوند و در این مبلغ نیستند.',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
            ),
          ],
        ],
      ),
    );
  }
}

class _AccountLine extends StatelessWidget {
  const _AccountLine({
    required this.icon,
    required this.label,
    required this.value,
    this.isProblem = false,
  });

  final IconData icon;
  final String label;
  final String value;
  final bool isProblem;

  @override
  Widget build(BuildContext context) {
    final color = isProblem
        ? const Color(0xFFD1495B)
        : Theme.of(context).colorScheme.onSurfaceVariant;

    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: [
          Icon(icon, size: 16, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(color: color),
            ),
          ),
          Text(
            value,
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: color,
                ),
          ),
        ],
      ),
    );
  }
}


/// Shown while the admin has not answered yet, so the seller does not send
/// the same request twice wondering whether it went through.
class _PendingNotice extends StatelessWidget {
  const _PendingNotice({required this.request});

  final SettlementRequest request;

  @override
  Widget build(BuildContext context) {
    const pending = Color(0xFF3B82C4);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: pending.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: pending.withValues(alpha: 0.35)),
      ),
      child: Row(
        children: [
          const Icon(Icons.hourglass_top_rounded, size: 18, color: pending),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'درخواست تسویه ${request.amountFormatted} در انتظار تأیید مدیر',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: pending,
                        fontWeight: FontWeight.w700,
                      ),
                ),
                if (request.requestedOnDisplay != null)
                  Text(
                    request.requestedOnDisplay!,
                    style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: Theme.of(context).colorScheme.onSurfaceVariant,
                        ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Why the last request did not go through — without it the seller only
/// sees that the account never cleared.
class _RejectionNotice extends StatelessWidget {
  const _RejectionNotice({required this.reason});

  final String reason;

  @override
  Widget build(BuildContext context) {
    const rejected = Color(0xFFD1495B);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: rejected.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.info_outline_rounded, size: 18, color: rejected),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              'درخواست قبلی رد شد: $reason',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: rejected,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}


/// Asks the seller how the handover was made, type by type.
///
/// The shop settles in more ways than one — some by hand, some through the
/// reader, some left at the house — and the admin counting it wants the
/// same breakdown the seller counted out, not one lump sum.
class _SettlementSplitDialog extends StatefulWidget {
  const _SettlementSplitDialog({required this.account});

  final SellerAccount account;

  @override
  State<_SettlementSplitDialog> createState() => _SettlementSplitDialogState();
}

class _SettlementSplitDialogState extends State<_SettlementSplitDialog> {
  /// Bread given away brings in no money, so it is never handed over.
  static final List<PaymentType> _types = PaymentType.values
      .where((type) => !type.expectsNoAmount)
      .toList();

  late final Map<PaymentType, TextEditingController> _fields = {
    for (final type in _types)
      type: TextEditingController(
        // The usual case is the whole amount in cash, so only the
        // exceptions have to be typed.
        text: type == PaymentType.cash && widget.account.settleable > 0
            ? widget.account.settleable.toStringAsFixed(0)
            : '',
      ),
  };

  @override
  void dispose() {
    for (final field in _fields.values) {
      field.dispose();
    }
    super.dispose();
  }

  double _valueOf(PaymentType type) =>
      double.tryParse(_fields[type]!.text.trim()) ?? 0;

  double get _entered =>
      _types.fold(0.0, (sum, type) => sum + _valueOf(type));

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final owed = widget.account.settleable;
    final mismatch = (_entered - owed).abs() > 0.5;

    return AlertDialog(
      title: const Text('تسویه حساب'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'مبلغ قابل تسویه: ${widget.account.settleableFormatted}',
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
            ),
            const SizedBox(height: 6),
            Text(
              'مبلغ هر نوع پرداخت را جدا وارد کنید.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
            ),
            const SizedBox(height: 12),
            for (final type in _types)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: TextField(
                  controller: _fields[type],
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    labelText: type.label,
                    isDense: true,
                  ),
                ),
              ),
            Row(
              children: [
                Expanded(
                  child: Text(
                    'جمع واردشده',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
                Text(
                  _entered.toStringAsFixed(0),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        fontWeight: FontWeight.w800,
                        color: mismatch ? scheme.error : null,
                      ),
                ),
              ],
            ),
            if (mismatch)
              Text(
                'جمع مبالغ با حساب شما برابر نیست.',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: scheme.error,
                    ),
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('انصراف'),
        ),
        FilledButton(
          onPressed: _entered <= 0
              ? null
              : () => Navigator.pop(context, {
                    for (final type in _types)
                      if (_valueOf(type) > 0) type: _valueOf(type),
                  }),
          child: const Text('ارسال درخواست'),
        ),
      ],
    );
  }
}
