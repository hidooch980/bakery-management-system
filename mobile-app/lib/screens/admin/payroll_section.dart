import 'package:flutter/material.dart';

import '../../models/payroll.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
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

typedef _Payroll = ({List<Employee> staff, List<Payslip> slips});

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

    return (staff: staff, slips: slips);
  }

  void _reload() => setState(() => _data = _load());

  /// The period a payslip belongs to: the first of the Jalali month that
  /// is being paid for. Sent as the shop writes dates, not as ISO.
  String get _thisPeriod {
    final now = JalaliFormat.date(DateTime.now());
    final parts = now.split('/');

    return parts.length == 3 ? '${parts[0]}/${parts[1]}/01' : now;
  }

  Future<void> _pay(Employee person) async {
    final result = await showModalBottomSheet<_PayInput>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _PaySheet(person: person),
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

        // Who has already been paid for the period showing, so the list
        // does not offer to pay the same person twice.
        final paidThisPeriod = slips
            .where((s) => s.periodLabel == _periodLabel(slips))
            .map((s) => s.userName)
            .toSet();

        return AdminSection(
          title: 'حقوق کارکنان',
          icon: Icons.payments_rounded,
          children: [
            if (staff.isEmpty)
              const AdminRow(label: 'کارمندی ثبت نشده', value: '—')
            else
              for (final person in staff)
                AdminRow(
                  label: person.name,
                  value: paidThisPeriod.contains(person.name)
                      ? 'پرداخت شد'
                      : person.monthlySalaryFormatted,
                  icon: paidThisPeriod.contains(person.name)
                      ? Icons.check_circle_rounded
                      : Icons.arrow_circle_left_rounded,
                  color: paidThisPeriod.contains(person.name) ? AppColors.moneyIn : null,
                  onTap: paidThisPeriod.contains(person.name) ? null : () => _pay(person),
                ),
            if (slips.isNotEmpty) ...[
              const Divider(height: 24),
              for (final slip in slips.take(6))
                AdminRow(
                  label: '${slip.userName}  •  ${slip.periodLabel}',
                  value: slip.netAmountFormatted,
                  color: slip.isPaid ? AppColors.moneyIn : AppColors.attention,
                ),
            ],
          ],
        );
      },
    );
  }

  /// The newest period on file, which is the one the buttons are about.
  String _periodLabel(List<Payslip> slips) =>
      slips.isEmpty ? '' : slips.first.periodLabel;
}

typedef _PayInput = ({double base, double bonus, double deduction, String? note});

/// One person's pay, opened on what was agreed for them.
class _PaySheet extends StatefulWidget {
  const _PaySheet({required this.person});

  final Employee person;

  @override
  State<_PaySheet> createState() => _PaySheetState();
}

class _PaySheetState extends State<_PaySheet> {
  late final TextEditingController _base;
  final _bonus = TextEditingController();
  final _deduction = TextEditingController();
  final _note = TextEditingController();

  @override
  void initState() {
    super.initState();

    // Filled in with the agreed wage. The ordinary month is everybody on
    // their usual figure, and a form that starts empty is a form that
    // makes the ordinary month the most work.
    _base = TextEditingController(
      text: widget.person.monthlySalary == 0
          ? ''
          : widget.person.monthlySalary.toStringAsFixed(0),
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

  /// What was typed, as a number. The grouping formatter puts separators
  /// in and Persian keyboards put Persian digits in; neither parses.
  double _read(TextEditingController c) {
    const persian = '۰۱۲۳۴۵۶۷۸۹';
    final buffer = StringBuffer();

    for (final rune in c.text.runes) {
      final char = String.fromCharCode(rune);
      final digit = persian.indexOf(char);

      if (digit >= 0) {
        buffer.write(digit);
      } else if (RegExp(r'[0-9]').hasMatch(char)) {
        buffer.write(char);
      }
    }

    return double.tryParse(buffer.toString()) ?? 0;
  }

  double get _net => _read(_base) + _read(_bonus) - _read(_deduction);

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
              _Field(controller: _bonus, label: 'پاداش', onChanged: _refresh),
              const SizedBox(height: 12),
              _Field(controller: _deduction, label: 'کسورات', onChanged: _refresh),
              const SizedBox(height: 12),
              TextField(
                controller: _note,
                decoration: const InputDecoration(labelText: 'توضیح (اختیاری)'),
              ),
              const SizedBox(height: 20),
              // The net, shown as it is typed. It is the server's
              // arithmetic that is stored — this is the same sum done here
              // so nobody presses the button on a figure they have not
              // seen.
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(Corner.control),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('خالص پرداختی', style: theme.textTheme.bodyMedium),
                    Text(
                      MoneyFormat.plain(_net),
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w800,
                        fontFeatures: const [FontFeature.tabularFigures()],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),
              FilledButton.icon(
                onPressed: _net <= 0
                    ? null
                    : () => Navigator.pop(context, (
                          base: _read(_base),
                          bonus: _read(_bonus),
                          deduction: _read(_deduction),
                          note: _note.text.trim().isEmpty ? null : _note.text.trim(),
                        )),
                icon: const Icon(Icons.check_rounded),
                label: const Text('پرداخت شد'),
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
