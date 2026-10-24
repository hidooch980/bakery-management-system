import 'package:flutter/material.dart';

import '../../models/bank_account.dart';
import '../../models/payroll.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/one_write.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import 'adjustment_sheet.dart';
import 'staff_account_screen.dart';
import 'salary_settlement_review.dart';
import 'admin_home_screen.dart';

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
  /// نام‌های نوشتن‌هایی که هنوز نگرفته‌اند، تا تلاش دوباره همان را
  /// ببرد و یک جوابِ گم‌شده به دو فیش تبدیل نشود.
  final _writes = OneWrite();

  late Future<_Payroll> _data;
  bool _paymentOpen = false;

  @override
  void initState() {
    super.initState();
    _data = _load();
  }

  Future<_Payroll> _load() async {
    final staff = await widget.api.payrollEmployees();
    final slips = await widget.api.payslips();

    final balances = await widget.api.bankBalances();

    return (
      staff: staff,
      slips: slips,
      accounts: balances.accounts.where((a) => a.isActive).toList(),
    );
  }

  void _reload() => setState(() {
        _data = _load();
      });

  Future<void> _addAdjustment(List<Employee> staff) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => AdjustmentSheet(api: widget.api, staff: staff),
    );

    if (saved == true) _reload();
  }

  String get _thisPeriod {
    final now = latinDigits(JalaliFormat.date(DateTime.now()));
    final parts = now.split('/');

    return parts.length == 3 ? '${parts[0]}/${parts[1]}/01' : now;
  }

  /// در زمان باز بودن فرم و انتظار پاسخ، مسیر پرداخت دوباره اجرا نمی‌شود.
  Future<void> _withPayment(Future<void> Function() action) async {
    if (_paymentOpen) return;
    setState(() => _paymentOpen = true);
    try {
      await action();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, isError: true);
    } on StateError catch (e) {
      if (mounted) {
        showMessage(context, e.message, isError: true);
        _reload();
      }
    } catch (_) {
      if (mounted) {
        showMessage(context,
            'پاسخ قطعی دریافت نشد؛ پیش از تلاش دوباره پرونده کارکنان را بررسی کنید.',
            isError: true);
      }
    } finally {
      if (mounted) setState(() => _paymentOpen = false);
    }
  }

  /// پرونده تازه هم بدهی‌ها را می‌دهد و هم فیشی را که دستگاه دیگری ثبت کرده است.
  Future<({double advance, double bread, String currency})> _freshAccount(
      int personId, String period) async {
    final data = await widget.api.staffAccount(personId);
    final summary = data['summary'];
    final slips = data['payslips'];
    if (summary is! Map ||
        slips is! List ||
        data['currency_label'] is! String) {
      throw StateError('پرونده کامل دریافت نشد؛ تسویه ثبت نشد.');
    }
    if (slips
        .whereType<Map>()
        .any((s) => latinDigits('${s['period_start_jalali']}') == period)) {
      throw StateError(
          'برای این کارمند در این دوره قبلاً فیش ثبت شده است؛ فیش موجود را از پرونده پیگیری کنید.');
    }
    double amount(String key) {
      final value = double.tryParse('${summary[key]}');
      if (value == null || !value.isFinite || value < 0) {
        throw StateError('مانده بدهی معتبر دریافت نشد؛ تسویه ثبت نشد.');
      }
      return value;
    }

    return (
      advance: amount('advance_outstanding'),
      bread: amount('bread_outstanding'),
      currency: data['currency_label'] as String
    );
  }

  Future<void> _pay(Employee person, List<BankAccount> accounts) =>
      _withPayment(() => _preparePay(person, accounts));

  Future<void> _preparePay(Employee person, List<BankAccount> accounts) async {
    final period = _thisPeriod;
    final result = await showModalBottomSheet<_PayInput>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _PaySheet(person: person, accounts: accounts),
    );

    if (result == null || !mounted) return;
    final before = await _freshAccount(person.id, period);
    if (!mounted) return;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => SalarySettlementReview(
        personName: person.displayName,
        period: period,
        accountTitle: result.accountId == null
            ? 'صندوق'
            : accounts.firstWhere((a) => a.id == result.accountId).title,
        currency: before.currency,
        note: result.note,
        settlement: SalarySettlement(
            base: result.base,
            bonus: result.bonus,
            deduction: result.deduction,
            advanceOwed: before.advance,
            breadOwed: before.bread,
            recoverAdvances: result.recoverAdvances,
            recoverBread: result.recoverBread),
      ),
    );
    if (confirmed != true || !mounted) return;
    final after = await _freshAccount(person.id, period);
    if (!mounted) return;
    if (before != after || period != _thisPeriod) {
      throw StateError(
          'پرونده یا دوره حقوق تغییر کرده است؛ برای دیدن مبلغ تازه، تسویه را دوباره باز کنید.');
    }

    // قصد، خودِ فیش است: همان کارگر، همان ماه، همان عددها. اگر مالک
    // بعداً عدد دیگری بزند، کار دیگری است و نام دیگری می‌گیرد.
    final intent = 'salary-${person.id}-$period'
        '-${result.base}-${result.bonus}-${result.deduction}'
        '-${result.recoverAdvances}-${result.recoverBread}-${result.accountId}';

    try {
      await widget.api.recordSalary(
        userId: person.id,
        periodStart: period,
        baseAmount: result.base,
        bonus: result.bonus,
        deduction: result.deduction,
        recoverAdvances: result.recoverAdvances,
        recoverBread: result.recoverBread,
        attemptKey: _writes.nameFor(intent),
        paidOn: JalaliFormat.date(DateTime.now()),
        bankAccountId: result.accountId,
        note: result.note,
      );

      _writes.done(intent);

      if (!mounted) return;
      showMessage(context, 'حقوق ${person.displayName} ثبت شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _handOver(
          Employee person, Payslip slip, List<BankAccount> accounts) =>
      _withPayment(() => _prepareHandOver(person, slip, accounts));

  Future<void> _prepareHandOver(
    Employee person,
    Payslip slip,
    List<BankAccount> accounts,
  ) async {
    final answer = await showModalBottomSheet<_HandOver>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _HandOverSheet(slip: slip, accounts: accounts),
    );

    if (answer == null || !mounted) return;

    try {
      await widget.api.markSalaryPaid(
        slip.id,
        bankAccountId: answer.accountId,
      );

      if (!mounted) return;

      showMessage(context, 'پرداخت ${person.displayName} ثبت شد.');
      _reload();
    } on ApiException catch (e) {
      if (mounted) showMessage(context, e.message, isError: true);
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

        final thisPeriod =
            slips.where((s) => s.periodStartJalali == _thisPeriod);

        final paidThisPeriod =
            thisPeriod.where((s) => s.isPaid).map((s) => s.userId).toSet();

        final awaiting = {
          for (final slip in thisPeriod.where((s) => !s.isPaid))
            slip.userId: slip,
        };

        return AdminSection(
          title: 'حقوق کارکنان',
          icon: Icons.payments_rounded,
          children: [
            if (_paymentOpen) ...[
              const Text('در حال بررسی تسویه؛ این مرحله را کامل کنید.'),
            ],
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
                  label: 'پرونده و ریز حساب ${person.displayName}',
                  value: 'حقوق و بدهی‌ها',
                  icon: Icons.account_balance_wallet_outlined,
                  onTap: () async {
                    await Navigator.push(
                        context,
                        MaterialPageRoute<void>(
                          builder: (_) => StaffAccountScreen(
                              api: widget.api, person: person),
                        ));
                    if (mounted) _reload();
                  },
                ),
                if (paidThisPeriod.contains(person.id))
                  AdminRow(
                    label: person.displayName,
                    value: 'پرداخت شد',
                    icon: Icons.check_circle_rounded,
                    color: AppColors.moneyIn,
                  )
                else if (awaiting[person.id] case final slip?)
                  AdminRow(
                    label: person.displayName,
                    value: 'پرداخت نشده — ${slip.netAmountFormatted}',
                    icon: Icons.hourglass_bottom_rounded,
                    color: AppColors.attention,
                    onTap: () => _handOver(person, slip, accounts),
                  )
                else
                  AdminRow(
                    label: person.displayName,
                    value: person.monthlySalaryFormatted,
                    icon: Icons.arrow_circle_left_rounded,
                    onTap: () => _pay(person, accounts),
                  ),
                if (person.hasRequested && !paidThisPeriod.contains(person.id))
                  Padding(
                    padding: const EdgeInsetsDirectional.only(
                        start: 4, bottom: Gap.tight),
                    child: Row(
                      children: [
                        const Icon(Icons.event_available_rounded,
                            size: IconSize.inline, color: AppColors.attention),
                        const SizedBox(width: Gap.tight),
                        Expanded(
                            child: Text(
                          person.requestedDaysAgo == null ||
                                  person.requestedDaysAgo == 0
                              ? 'امروز درخواست پرداخت داد'
                              : '${person.requestedDaysAgo} روز پیش درخواست پرداخت داد',
                          style: Theme.of(context)
                              .textTheme
                              .bodySmall
                              ?.copyWith(color: AppColors.attention),
                        )),
                      ],
                    ),
                  ),
                if ((person.owesAdvance || person.owesBread) &&
                    !paidThisPeriod.contains(person.id))
                  Padding(
                    padding: const EdgeInsetsDirectional.only(
                        start: 4, bottom: Gap.tight),
                    child: Row(
                      children: [
                        const Icon(Icons.remove_circle_outline_rounded,
                            size: IconSize.inline, color: AppColors.moneyOut),
                        const SizedBox(width: Gap.tight),
                        Expanded(
                            child: Text(
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
                        )),
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
  bool recoverAdvances,
  bool recoverBread,
  int? accountId,
  String? note,
});

class _PaySheet extends StatefulWidget {
  const _PaySheet({required this.person, required this.accounts});

  final Employee person;
  final List<BankAccount> accounts;

  @override
  State<_PaySheet> createState() => _PaySheetState();
}

class _PaySheetState extends State<_PaySheet> {
  bool _recoverAdvances = true;
  bool _recoverBread = true;
  late final TextEditingController _base;
  late final TextEditingController _bonus;
  late final TextEditingController _deduction;
  final _note = TextEditingController();

  int? _accountId;

  @override
  void initState() {
    super.initState();

    final suggested = widget.person.suggestedBankAccountId;

    _accountId = widget.accounts.any((a) => a.id == suggested)
        ? suggested
        : (widget.accounts.isEmpty ? null : widget.accounts.first.id);

    _base = TextEditingController(
      text: widget.person.monthlySalary == 0
          ? ''
          : widget.person.monthlySalary.toStringAsFixed(0),
    );

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

  double _read(TextEditingController c) =>
      MoneyFormat.parseInput(c.text.trim()) ?? 0;

  SalarySettlement get _settlement => SalarySettlement(
      base: _read(_base),
      bonus: _read(_bonus),
      deduction: _read(_deduction),
      advanceOwed: widget.person.advanceOutstanding,
      breadOwed: widget.person.breadOutstanding,
      recoverAdvances: _recoverAdvances,
      recoverBread: _recoverBread);

  double get _gross => _settlement.gross;
  double get _advance => _settlement.advance;
  double get _bread => _settlement.bread;
  double get _net => _settlement.net;

  bool get _carriesOver =>
      (widget.person.advanceOutstanding - _advance) +
          (widget.person.breadOutstanding - _bread) >
      0;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding:
          EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'حقوق ${widget.person.displayName}',
                style: theme.textTheme.titleLarge
                    ?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 20),
              _Field(
                  controller: _base, label: 'حقوق پایه', onChanged: _refresh),
              const SizedBox(height: 12),
              _Field(
                  controller: _bonus,
                  label: 'پاداش و اضافه‌کاری',
                  onChanged: _refresh),
              const SizedBox(height: 12),
              _Field(
                  controller: _deduction,
                  label: 'تنبیهی و کسورات',
                  onChanged: _refresh),
              if (widget.person.owesAdvance)
                CheckboxListTile(
                  title: const Text('کسر مساعده از این حقوق'),
                  subtitle: Text(widget.person.advanceOutstandingFormatted),
                  value: _recoverAdvances,
                  onChanged: (value) =>
                      setState(() => _recoverAdvances = value ?? false),
                ),
              if (widget.person.owesBread)
                CheckboxListTile(
                  title: const Text('کسر بدهی نان از این حقوق'),
                  subtitle: Text(widget.person.breadOutstandingFormatted),
                  value: _recoverBread,
                  onChanged: (value) =>
                      setState(() => _recoverBread = value ?? false),
                ),
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
                        onSelected: (_) =>
                            setState(() => _accountId = account.id),
                        label: Text(
                            '${account.title}  ${account.balanceFormatted}'),
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
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(Corner.control),
                ),
                child: Column(
                  children: [
                    ...[
                      _SumLine(label: 'حقوق پایه', amount: _read(_base)),
                      _SumLine(
                          label: 'پاداش و اضافه‌کاری', amount: _read(_bonus)),
                      _SumLine(
                          label: 'کسورات دستی', amount: -_read(_deduction)),
                      if (_advance > 0) ...[
                        const SizedBox(height: Gap.tight),
                        _SumLine(
                          label: 'کسر علی‌الحساب',
                          amount: -_advance,
                          color: AppColors.moneyOut,
                        ),
                      ],
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
                onPressed: _gross <= 0
                    ? null
                    : () => Navigator.pop(context, (
                          base: _read(_base),
                          bonus: _read(_bonus),
                          deduction: _read(_deduction),
                          recoverAdvances: _recoverAdvances,
                          recoverBread: _recoverBread,
                          accountId: _accountId,
                          note: _note.text.trim().isEmpty
                              ? null
                              : _note.text.trim(),
                        )),
                icon: const Icon(Icons.check_rounded),
                label: const Text('پیش‌نمایش تسویه'),
                style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(52)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  void _refresh(String _) => setState(() {});
}

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
    final style =
        strong ? theme.textTheme.titleMedium : theme.textTheme.bodyMedium;

    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Expanded(
            child: Text(
          label,
          style: theme.textTheme.bodyMedium?.copyWith(color: color),
        )),
        const SizedBox(width: 8),
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
  const _Field(
      {required this.controller, required this.label, required this.onChanged});

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

typedef _HandOver = ({int? accountId});

const int _fromTill = 0;

class _HandOverSheet extends StatefulWidget {
  const _HandOverSheet({required this.slip, required this.accounts});

  final Payslip slip;
  final List<BankAccount> accounts;

  @override
  State<_HandOverSheet> createState() => _HandOverSheetState();
}

class _HandOverSheetState extends State<_HandOverSheet> {
  int _accountId = _fromTill;

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
            'پرداخت حقوق ${widget.slip.userName}',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 6),
          Text(
            'فیش ${widget.slip.periodLabel} از قبل ثبت شده و پرداخت نشده'
            ' است. مبلغ: ${widget.slip.netAmountFormatted}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          const SizedBox(height: 16),
          if (widget.accounts.isNotEmpty)
            DropdownButtonFormField<int>(
              initialValue: _accountId,
              isExpanded: true,
              decoration: const InputDecoration(
                labelText: 'از کدام حساب',
                prefixIcon: Icon(Icons.account_balance_rounded),
              ),
              items: [
                const DropdownMenuItem(value: _fromTill, child: Text('صندوق')),
                for (final account in widget.accounts)
                  DropdownMenuItem(
                    value: account.id,
                    child: Text(account.title),
                  ),
              ],
              onChanged: (v) => setState(() => _accountId = v ?? _fromTill),
            ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: () => Navigator.pop(context,
                (accountId: _accountId == _fromTill ? null : _accountId,)),
            child: const Text('پرداخت شد'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('بی‌خیال'),
          ),
        ],
      ),
    );
  }
}
