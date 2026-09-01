import 'package:flutter/material.dart';

import '../../models/bank_account.dart';
import '../../models/payroll.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import 'adjustment_sheet.dart';
import 'admin_home_screen.dart';

/// Paying wages, from the phone.
///
/// This shop pays at the end of each Jalali month and had recorded not one
/// payslip since it opened — so every profit figure it has ever printed
/// was overstated by the whole payroll, silently. The reason was not
/// neglect: paying meant opening the panel on a computer, and the owner
/// carries a phone.
///
/// So the payroll is here. It opens on everyone who works at the shop with
/// what was agreed for them already filled in, because the ordinary month
/// is everybody on their usual wage and typing five numbers to say so is
/// how a payroll gets skipped.
class PayrollSection extends StatefulWidget {
  const PayrollSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<PayrollSection> createState() => _PayrollSectionState();
}

typedef _Payroll = ({
  List<Employee> staff,
  List<Payslip> slips,
  List<BankAccount> accounts,
});

class _PayrollSectionState extends State<PayrollSection> {
  late Future<_Payroll> _data;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<_Payroll> _load() async {
    final staff = await widget.api.payrollEmployees();
    final slips = await widget.api.payslips();

    // The accounts too, because a wage has to come out of one. A payslip
    // with no account records the cost and moves nothing: the money is
    // handed over, the balance does not fall, and the bank stops
    // reconciling with nothing on the page to say why.
    final balances = await widget.api.bankBalances();

    return (
      staff: staff,
      slips: slips,
      accounts: balances.accounts.where((a) => a.isActive).toList(),
    );
  }

  void _reload() => setState(() => _data = _load());

  /// Writing one down as it happens, rather than recalling it at payday.
  Future<void> _addAdjustment(List<Employee> staff) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => AdjustmentSheet(api: widget.api, staff: staff),
    );

    if (saved == true) _reload();
  }

  /// The period a payslip belongs to: the first of the Jalali month that
  /// is being paid for. Sent as the shop writes dates, not as ISO.
  ///
  /// In Latin digits, which is what the server sends back for the same
  /// field. Half-Persian and half-Latin parsed correctly but would never
  /// compare equal to anything, and comparing is what it is for.
  String get _thisPeriod {
    final now = latinDigits(JalaliFormat.date(DateTime.now()));
    final parts = now.split('/');

    return parts.length == 3 ? '${parts[0]}/${parts[1]}/01' : now;
  }

  Future<void> _pay(Employee person, List<BankAccount> accounts) async {
    final result = await showModalBottomSheet<_PayInput>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _PaySheet(person: person, accounts: accounts),
    );

    if (result == null) return;

    try {
      await widget.api.recordSalary(
        userId: person.id,
        periodStart: _thisPeriod,
        baseAmount: result.base,
        bonus: result.bonus,
        deduction: result.deduction,
        // Recorded as handed over, because that is what pressing «پرداخت
        // شد» means. A slip prepared before payday is a different action
        // and does not exist yet.
        paidOn: JalaliFormat.date(DateTime.now()),
        bankAccountId: result.accountId,
        note: result.note,
      );

      if (!mounted) return;
      showMessage(context, 'حقوق ${person.name} ثبت شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<_Payroll>(
      future: _data,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          );
        }

        if (snapshot.hasError) {
          return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
        }

        final staff = snapshot.data!.staff;
        final slips = snapshot.data!.slips;
        final accounts = snapshot.data!.accounts;

        // Who has already been paid for the period the button would write,
        // so the list does not offer to pay the same person twice.
        //
        // Keyed on that period and on the person's id. It used to key on
        // whichever period was newest on file, which is the same period only
        // until a month turns: from the first of the next month everyone
        // paid in the last one reads as already paid, and the payroll shuts
        // itself for a month it has not paid at all.
        final paidThisPeriod = slips
            .where((s) => s.periodStartJalali == _thisPeriod)
            .map((s) => s.userId)
            .toSet();

        return AdminSection(
          title: 'حقوق کارکنان',
          icon: Icons.payments_rounded,
          children: [
            if (staff.isNotEmpty)
              AdminRow(
                label: 'ثبت تشویقی یا تنبیهی',
                value: 'امروز',
                icon: Icons.add_task_rounded,
                color: AppColors.signalFor(Theme.of(context).brightness),
                onTap: () => _addAdjustment(staff),
              ),
            if (staff.isEmpty)
              const AdminRow(label: 'کارمندی ثبت نشده', value: '—')
            else
              for (final person in staff) ...[
                AdminRow(
                  label: person.name,
                  value: paidThisPeriod.contains(person.id)
                      ? 'پرداخت شد'
                      : person.monthlySalaryFormatted,
                  icon: paidThisPeriod.contains(person.id)
                      ? Icons.check_circle_rounded
                      : Icons.arrow_circle_left_rounded,
                  color: paidThisPeriod.contains(person.id) ? AppColors.moneyIn : null,
                  onTap: paidThisPeriod.contains(person.id)
                      ? null
                      : () => _pay(person, accounts),
                ),
                // He has asked. Shown before the row is tapped, because a
                // person chasing their own wage is the one thing on this
                // screen that is about somebody waiting.
                if (person.hasRequested && !paidThisPeriod.contains(person.id))
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 4, bottom: Gap.tight),
                    child: Row(
                      children: [
                        const Icon(Icons.event_available_rounded,
                            size: IconSize.inline, color: AppColors.attention),
                        const SizedBox(width: Gap.tight),
                        Text(
                          person.requestedDaysAgo == null || person.requestedDaysAgo == 0
                              ? 'امروز درخواست پرداخت داد'
                              : '${person.requestedDaysAgo} روز پیش درخواست پرداخت داد',
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(color: AppColors.attention),
                        ),
                      ],
                    ),
                  ),
                // Before the sheet is opened, not after. The wage on the row
                // above is not what this person is going to be handed, and
                // finding that out only once the sum is on screen is how the
                // deduction came to look like it was not happening.
                if ((person.owesAdvance || person.owesBread) &&
                    !paidThisPeriod.contains(person.id))
                  Padding(
                    padding: const EdgeInsetsDirectional.only(start: 4, bottom: Gap.tight),
                    child: Row(
                      children: [
                        const Icon(Icons.remove_circle_outline_rounded,
                            size: IconSize.inline, color: AppColors.moneyOut),
                        const SizedBox(width: Gap.tight),
                        Text(
                          person.owesAdvance && person.owesBread
                              ? 'علی‌الحساب ${person.advanceOutstandingFormatted}'
                                  '  •  نان ${person.breadOutstandingFormatted}'
                              : person.owesAdvance
                                  ? 'علی‌الحساب ${person.advanceOutstandingFormatted}'
                                  : 'نان برده‌شده ${person.breadOutstandingFormatted}',
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(color: AppColors.moneyOut),
                        ),
                      ],
                    ),
                  ),
              ],
            if (slips.isNotEmpty) ...[
              const Divider(height: 24),
              for (final slip in slips.take(6))
                AdminRow(
                  label: slip.recoveredAdvance || slip.recoveredBread
                      ? '${slip.userName}  •  ${slip.periodLabel}\n'
                          'پس از کسر ${[
                          if (slip.recoveredAdvance)
                            '${slip.advanceDeductionFormatted} علی‌الحساب',
                          if (slip.recoveredBread)
                            '${slip.breadDeductionFormatted} نان',
                        ].join(' و ')}'
                      : '${slip.userName}  •  ${slip.periodLabel}'
                          '${slip.bankAccountTitle == null ? '' : '  •  ${slip.bankAccountTitle}'}',
                  value: slip.netAmountFormatted,
                  color: slip.isPaid ? AppColors.moneyIn : AppColors.attention,
                ),
            ],
          ],
        );
      },
    );
  }
}

typedef _PayInput = ({
  double base,
  double bonus,
  double deduction,
  int? accountId,
  String? note,
});

/// One person's pay, opened on what was agreed for them.
class _PaySheet extends StatefulWidget {
  const _PaySheet({required this.person, required this.accounts});

  final Employee person;
  final List<BankAccount> accounts;

  @override
  State<_PaySheet> createState() => _PaySheetState();
}

class _PaySheetState extends State<_PaySheet> {
  late final TextEditingController _base;
  late final TextEditingController _bonus;
  late final TextEditingController _deduction;
  final _note = TextEditingController();

  int? _accountId;

  @override
  void initState() {
    super.initState();

    // Opened on the account this person's money last came from. It is the
    // field a tired owner skips at the end of a long month, and skipping it
    // is what makes a wage that costs the shop nothing.
    final suggested = widget.person.suggestedBankAccountId;

    _accountId = widget.accounts.any((a) => a.id == suggested)
        ? suggested
        : (widget.accounts.isEmpty ? null : widget.accounts.first.id);

    // Filled in with the agreed wage. The ordinary month is everybody on
    // their usual figure, and a form that starts empty is a form that
    // makes the ordinary month the most work.
    _base = TextEditingController(
      text: widget.person.monthlySalary == 0
          ? ''
          : widget.person.monthlySalary.toStringAsFixed(0),
    );

    // Opened on this month's rewards and penalties, already totalled from
    // what was written down as it happened. These two boxes used to start
    // empty and be filled from memory at the end of a long month, which is
    // how a figure gets guessed at.
    //
    // Still editable: the server does not add these during save, so
    // whatever is here when the button is pressed is what the shop owes.
    _bonus = TextEditingController(
      text: widget.person.suggestedBonus == 0
          ? ''
          : widget.person.suggestedBonus.toStringAsFixed(0),
    );

    _deduction = TextEditingController(
      text: widget.person.suggestedDeduction == 0
          ? ''
          : widget.person.suggestedDeduction.toStringAsFixed(0),
    );
  }

  @override
  void dispose() {
    _base.dispose();
    _bonus.dispose();
    _deduction.dispose();
    _note.dispose();
    super.dispose();
  }

  /// What was typed, as a number. The grouping formatter puts separators in
  /// and Persian keyboards put Persian digits in; neither parses. The same
  /// reading the flour sheet does, through the same helper.
  double _read(TextEditingController c) =>
      MoneyFormat.parseInput(c.text.trim()) ?? 0;

  double get _gross => _read(_base) + _read(_bonus) - _read(_deduction);

  /// How much of the outstanding advance this payslip absorbs — never more
  /// than the pay itself, which is the same rule the server applies.
  double get _advance {
    if (_gross <= 0) return 0;

    final owed = widget.person.advanceOutstanding;

    return owed < _gross ? owed : _gross;
  }

  /// Bread the person took home, out of what the advance left. The same
  /// order the server uses — a sheet that showed a different net from the
  /// one about to be stored is the bug this shop spent 2026-08-17 finding
  /// in the panel's own preview.
  double get _bread {
    final left = _gross - _advance;

    if (left <= 0) return 0;

    final owed = widget.person.breadOutstanding;

    return owed < left ? owed : left;
  }

  double get _net => _gross - _advance - _bread;

  bool get _carriesOver =>
      (widget.person.advanceOutstanding - _advance) +
          (widget.person.breadOutstanding - _bread) >
      0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'حقوق ${widget.person.name}',
                style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 20),
              _Field(controller: _base, label: 'حقوق پایه', onChanged: _refresh),
              const SizedBox(height: 12),
              _Field(controller: _bonus, label: 'تشویقی', onChanged: _refresh),
              const SizedBox(height: 12),
              _Field(controller: _deduction, label: 'تنبیهی و کسورات', onChanged: _refresh),
              // A box that fills itself is a box the owner has to be able
              // to account for, or he will not trust the total under it.
              if (widget.person.hasAdjustments) ...[
                const SizedBox(height: Gap.tight),
                Row(
                  children: [
                    const Icon(Icons.receipt_long_rounded,
                        size: IconSize.inline, color: AppColors.moneyNeutral),
                    const SizedBox(width: Gap.tight),
                    Expanded(
                      child: Text(
                        'از ${widget.person.adjustmentCount} مورد ثبت‌شدهٔ این ماه',
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: AppColors.moneyNeutral),
                      ),
                    ),
                  ],
                ),
              ],
              if (widget.accounts.isNotEmpty) ...[
                const SizedBox(height: Gap.block),
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: Text('از حساب', style: theme.textTheme.bodySmall),
                ),
                const SizedBox(height: Gap.tight),
                Wrap(
                  spacing: Gap.tight,
                  runSpacing: Gap.tight,
                  children: [
                    for (final account in widget.accounts)
                      ChoiceChip(
                        selected: _accountId == account.id,
                        onSelected: (_) => setState(() => _accountId = account.id),
                        label: Text('${account.title}  ${account.balanceFormatted}'),
                      ),
                  ],
                ),
              ],
              const SizedBox(height: 12),
              TextField(
                controller: _note,
                decoration: const InputDecoration(labelText: 'توضیح (اختیاری)'),
              ),
              const SizedBox(height: 20),
              // The net, shown as it is typed. It is the server's arithmetic
              // that is stored — this is the same sum done here so nobody
              // presses the button on a figure they have not seen.
              //
              // Including the advance, which is the whole point. The server
              // always took it off; this panel used to show the wage before
              // it, so the figure agreed to and the figure paid were two
              // different numbers and only the first was ever on screen.
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(Corner.control),
                ),
                child: Column(
                  children: [
                    if (_advance > 0 || _bread > 0) ...[
                      _SumLine(label: 'حقوق و پاداش', amount: _gross),
                      if (_advance > 0) ...[
                        const SizedBox(height: Gap.tight),
                        _SumLine(
                          label: 'کسر علی‌الحساب',
                          amount: -_advance,
                          color: AppColors.moneyOut,
                        ),
                      ],
                      // Its own line, never folded into the advance: they
                      // are two different debts and the person will ask
                      // which one a deduction was.
                      if (_bread > 0) ...[
                        const SizedBox(height: Gap.tight),
                        _SumLine(
                          label: 'کسر نان برده‌شده',
                          amount: -_bread,
                          color: AppColors.moneyOut,
                        ),
                      ],
                      const Divider(height: 20),
                    ],
                    _SumLine(label: 'خالص پرداختی', amount: _net, strong: true),
                  ],
                ),
              ),
              // What an advance bigger than a month's pay does. It is not a
              // negative wage — the rest stands and comes off next month —
              // and being told that now is better than wondering next month
              // why the deduction is still there.
              if (_carriesOver) ...[
                const SizedBox(height: Gap.item),
                Row(
                  children: [
                    const Icon(Icons.info_outline_rounded,
                        size: IconSize.inline, color: AppColors.attention),
                    const SizedBox(width: Gap.tight),
                    Expanded(
                      child: Text(
                        '${MoneyFormat.plain((widget.person.advanceOutstanding - _advance) + (widget.person.breadOutstanding - _bread))} '
                        'از بدهی به ماه بعد می‌ماند.',
                        style: theme.textTheme.bodySmall
                            ?.copyWith(color: AppColors.attention),
                      ),
                    ),
                  ],
                ),
              ],
              const SizedBox(height: 20),
              FilledButton.icon(
                // On the gross, not the net. A wage entirely swallowed by an
                // advance still has to be recorded: that is the month the
                // debt gets worked off, and refusing to write it leaves the
                // advance outstanding forever.
                onPressed: _gross <= 0
                    ? null
                    : () => Navigator.pop(context, (
                          base: _read(_base),
                          bonus: _read(_bonus),
                          deduction: _read(_deduction),
                          accountId: _accountId,
                          note: _note.text.trim().isEmpty ? null : _note.text.trim(),
                        )),
                icon: const Icon(Icons.check_rounded),
                label: Text(_net <= 0 && _advance > 0
                    ? 'تسویه با علی‌الحساب'
                    : 'پرداخت شد'),
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _refresh(String _) => setState(() {});
}

/// One line of the pay sum: what it is, and how much.
class _SumLine extends StatelessWidget {
  const _SumLine({
    required this.label,
    required this.amount,
    this.color,
    this.strong = false,
  });

  final String label;
  final double amount;
  final Color? color;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = strong ? theme.textTheme.titleMedium : theme.textTheme.bodyMedium;

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: theme.textTheme.bodyMedium?.copyWith(color: color),
        ),
        Text(
          MoneyFormat.plain(amount),
          style: style?.copyWith(
            color: color,
            fontWeight: strong ? FontWeight.w800 : FontWeight.w600,
            fontFeatures: const [FontFeature.tabularFigures()],
          ),
        ),
      ],
    );
  }
}

class _Field extends StatelessWidget {
  const _Field({required this.controller, required this.label, required this.onChanged});

  final TextEditingController controller;
  final String label;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      keyboardType: const TextInputType.numberWithOptions(decimal: false),
      inputFormatters: [GroupedAmountInputFormatter()],
      onChanged: onChanged,
      decoration: InputDecoration(labelText: label),
    );
  }
}
