import 'package:flutter/material.dart';

import '../models/quota_and_advance.dart';
import '../screens/shared/my_advances_screen.dart';
import '../services/bakery_api.dart';
import '../theme/app_theme.dart';

/// What this person is owed, on their own home screen.
///
/// Their pay was the one figure in the shop they could not see. It lived in
/// a book in the office and in a screen only the admin opened, so the way to
/// find out what was left of your month was to ask — and asking is how a
/// wrong figure survives for months without anyone noticing.
///
/// The advance request sits here rather than three taps into the settings
/// menu, next to the number that explains why someone is asking.
class PayCard extends StatefulWidget {
  const PayCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<PayCard> createState() => _PayCardState();
}

class _PayCardState extends State<PayCard> {
  PaySummary? _pay;
  bool _loading = true;

  /// The figures could not be read. The card still draws with a way back in:
  /// somebody who cannot see their pay must at least be able to retry, and
  /// to ask for an advance regardless.
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    if (!_loading) setState(() => _loading = true);

    try {
      final pay = await widget.api.myPaySummary();
      if (!mounted) return;
      setState(() {
        _pay = pay;
        _loading = false;
        _failed = false;
      });
    } catch (_) {
      // Every failure, not only ApiException: a dropped connection left the
      // attendance card a permanent spinner once, and this card hides the
      // advance button the same way.
      if (!mounted) return;
      setState(() {
        _loading = false;
        _failed = true;
      });
    }
  }

  Future<void> _openAdvances() async {
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => MyAdvancesScreen(api: widget.api),
      ),
    );

    // Whatever happened in there — a request made, a request withdrawn —
    // changes what this card should say.
    if (mounted) await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pay = _pay;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(Icons.savings_outlined, color: AppColors.emberHot),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'حقوق من',
                    style: theme.textTheme.titleSmall
                        ?.copyWith(fontWeight: FontWeight.w800),
                  ),
                ),
                if (pay != null && pay.periodLabel.isNotEmpty)
                  Text(
                    pay.periodLabel,
                    style: theme.textTheme.bodySmall,
                  ),
              ],
            ),
            const SizedBox(height: 12),

            if (_loading)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 18),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (_failed || pay == null)
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'مانده حقوق خوانده نشد.',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                  TextButton(
                    onPressed: _refresh,
                    child: const Text('تلاش دوباره'),
                  ),
                ],
              )
            else ...[
              _headline(theme, pay),
              const SizedBox(height: 10),
              Text(pay.summary, style: theme.textTheme.bodySmall),
              if (pay.monthlySalaryFormatted != null ||
                  pay.owesAdvance) ...[
                const Divider(height: 22),
                if (pay.monthlySalaryFormatted != null)
                  _row(theme, 'حقوق ماهانه', pay.monthlySalaryFormatted!),
                if (pay.owesAdvance)
                  _row(
                    theme,
                    'علی‌الحساب تسویه‌نشده',
                    pay.advanceOutstandingFormatted,
                    colour: AppColors.emberHot,
                  ),
                if (pay.hasUnpaidPayslips)
                  _row(
                    theme,
                    'فیش پرداخت‌نشده (${pay.unpaidPayslipsCount} فیش)',
                    pay.unpaidPayslipsFormatted,
                    colour: AppColors.moneyIn,
                  ),
              ],
            ],

            const SizedBox(height: 6),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                onPressed: _openAdvances,
                icon: const Icon(Icons.pan_tool_alt_rounded, size: 18),
                label: Text(
                  pay?.hasPendingRequest == true
                      ? 'درخواست شما در انتظار پاسخ است'
                      : 'درخواست علی‌الحساب',
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// The one figure worth reading from across the room.
  ///
  /// An issued payslip outranks the forecast: it is money the shop has
  /// already accepted it owes, and burying it under an estimate of this
  /// month would be the wrong way round.
  Widget _headline(ThemeData theme, PaySummary pay) {
    final (label, value, colour) = switch (pay) {
      final p when p.hasUnpaidPayslips => (
          'پرداخت‌نشده',
          p.unpaidPayslipsFormatted,
          AppColors.moneyIn,
        ),
      // Not "this month's": the shop issues no payslips, so an advance is
      // a standing debt and is still being deducted months after it was
      // taken. Calling the result this month's remainder would blame the
      // current month for money drawn in an earlier one.
      final p when p.remainingFormatted != null => (
          'مانده حقوق پس از کسر بدهی',
          p.remainingFormatted!,
          AppColors.emberHot,
        ),
      _ => ('مانده حقوق', '—', AppColors.emberHot),
    };

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: theme.textTheme.bodySmall),
        const SizedBox(height: 2),
        Text(
          value,
          style: theme.textTheme.headlineSmall?.copyWith(
            fontWeight: FontWeight.w900,
            color: colour,
          ),
        ),
      ],
    );
  }

  Widget _row(ThemeData theme, String label, String value, {Color? colour}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
          Text(
            value,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w700,
              color: colour,
            ),
          ),
        ],
      ),
    );
  }
}
