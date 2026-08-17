import 'package:flutter/material.dart';

import '../../models/payroll.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// Writing down a reward or a penalty on the day it happened.
///
/// The payslip's two boxes used to be filled from memory at the end of a
/// long month. Nobody remembers who came in late on the 12th, so either it
/// was forgotten — which costs the shop — or guessed at, which costs the
/// person and cannot be defended when they ask.
///
/// This is one screen with one job: who, how much, and why. The «why» is
/// required, because a deduction nobody can explain a month later is one
/// its owner will dispute, and they will be right to.
class AdjustmentSheet extends StatefulWidget {
  const AdjustmentSheet({super.key, required this.api, required this.staff});

  final BakeryApi api;
  final List<Employee> staff;

  @override
  State<AdjustmentSheet> createState() => _AdjustmentSheetState();
}

enum _Basis { amount, days, note }

class _AdjustmentSheetState extends State<AdjustmentSheet> {
  final _amount = TextEditingController();
  final _reason = TextEditingController();

  Employee? _person;
  bool _isReward = true;
  _Basis _basis = _Basis.amount;
  double _days = 1;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _person = widget.staff.isEmpty ? null : widget.staff.first;
  }

  @override
  void dispose() {
    _amount.dispose();
    _reason.dispose();
    super.dispose();
  }

  /// What a day off this person's own wage comes to. The same half-day is
  /// worth different money to different people, which is what makes «a
  /// day's pay» a fair rule rather than a flat fine.
  double get _dayValue =>
      _person == null ? 0 : _person!.monthlySalary / 30;

  double get _worth => switch (_basis) {
        _Basis.amount => MoneyFormat.parseInput(_amount.text.trim()) ?? 0,
        _Basis.days => _dayValue * _days,
        _Basis.note => 0,
      };

  bool get _canSave {
    if (_person == null || _saving) return false;
    if (_reason.text.trim().length < 3) return false;

    return switch (_basis) {
      _Basis.amount => _worth > 0,
      _Basis.days => _days > 0 && _dayValue > 0,
      _Basis.note => true,
    };
  }

  Future<void> _save() async {
    setState(() => _saving = true);

    try {
      await widget.api.recordAdjustment(
        userId: _person!.id,
        kind: _isReward ? 'reward' : 'penalty',
        basis: switch (_basis) {
          _Basis.amount => 'amount',
          _Basis.days => 'days',
          _Basis.note => 'note',
        },
        reason: _reason.text.trim(),
        amount: _basis == _Basis.amount ? _worth : null,
        days: _basis == _Basis.days ? _days : null,
        occurredOn: JalaliFormat.date(DateTime.now()),
      );

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(context, '${_isReward ? 'تشویقی' : 'تنبیهی'} ثبت شد.');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final accent = _isReward ? AppColors.moneyIn : AppColors.moneyOut;

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
                'تشویقی و تنبیهی',
                style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: Gap.tight),
              Text(
                'همین حالا ثبتش کنید. پایان ماه خودش در فیش حقوقی می‌نشیند.',
                style: theme.textTheme.bodySmall
                    ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              ),
              const SizedBox(height: Gap.section),

              SegmentedButton<bool>(
                segments: const [
                  ButtonSegment(
                    value: true,
                    label: Text('تشویقی'),
                    icon: Icon(Icons.add_circle_outline_rounded),
                  ),
                  ButtonSegment(
                    value: false,
                    label: Text('تنبیهی'),
                    icon: Icon(Icons.remove_circle_outline_rounded),
                  ),
                ],
                selected: {_isReward},
                onSelectionChanged: (v) => setState(() => _isReward = v.first),
              ),
              const SizedBox(height: Gap.block),

              DropdownButtonFormField<int>(
                value: _person?.id,
                decoration: const InputDecoration(labelText: 'کارمند'),
                items: [
                  for (final p in widget.staff)
                    DropdownMenuItem(value: p.id, child: Text(p.name)),
                ],
                onChanged: (id) => setState(
                  () => _person = widget.staff.firstWhere((p) => p.id == id),
                ),
              ),
              const SizedBox(height: Gap.block),

              Align(
                alignment: AlignmentDirectional.centerStart,
                child: Text('بر چه مبنایی', style: theme.textTheme.bodySmall),
              ),
              const SizedBox(height: Gap.tight),
              Wrap(
                spacing: Gap.tight,
                children: [
                  ChoiceChip(
                    label: const Text('مبلغ'),
                    selected: _basis == _Basis.amount,
                    onSelected: (_) => setState(() => _basis = _Basis.amount),
                  ),
                  ChoiceChip(
                    label: const Text('روز'),
                    selected: _basis == _Basis.days,
                    onSelected: (_) => setState(() => _basis = _Basis.days),
                  ),
                  ChoiceChip(
                    label: const Text('فقط ثبت'),
                    selected: _basis == _Basis.note,
                    onSelected: (_) => setState(() => _basis = _Basis.note),
                  ),
                ],
              ),
              const SizedBox(height: Gap.block),

              if (_basis == _Basis.amount)
                TextField(
                  controller: _amount,
                  keyboardType: const TextInputType.numberWithOptions(decimal: false),
                  inputFormatters: [GroupedAmountInputFormatter()],
                  onChanged: (_) => setState(() {}),
                  decoration: const InputDecoration(labelText: 'مبلغ'),
                )
              else if (_basis == _Basis.days) ...[
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('تعداد روز', style: theme.textTheme.bodyMedium),
                    Text(
                      _days == 0.5 ? 'نیم روز' : '${_days.toStringAsFixed(_days % 1 == 0 ? 0 : 1)} روز',
                      style: theme.textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.w800, color: accent),
                    ),
                  ],
                ),
                Slider(
                  value: _days,
                  min: 0.5,
                  max: 10,
                  divisions: 19,
                  onChanged: (v) => setState(() => _days = v),
                ),
                // The rule priced against this person's own wage, so the
                // figure is not a surprise on the payslip.
                if (_dayValue <= 0)
                  Text(
                    'حقوق ماهانهٔ این کارمند ثبت نشده — روز به مبلغ تبدیل نمی‌شود.',
                    style: theme.textTheme.bodySmall
                        ?.copyWith(color: AppColors.attention),
                  ),
              ] else
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.surfaceContainerHighest,
                    borderRadius: BorderRadius.circular(Corner.control),
                  ),
                  child: Text(
                    'روی حقوق اثری ندارد؛ فقط در سابقه می‌ماند.',
                    style: theme.textTheme.bodySmall,
                  ),
                ),

              const SizedBox(height: Gap.block),
              TextField(
                controller: _reason,
                maxLength: 300,
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(
                  labelText: 'دلیل',
                  helperText: 'چند کلمه کافی است — ولی بدون دلیل ثبت نمی‌شود.',
                ),
              ),

              if (_basis != _Basis.note) ...[
                const SizedBox(height: Gap.item),
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: accent.withValues(alpha: 0.10),
                    borderRadius: BorderRadius.circular(Corner.control),
                    border: Border.all(color: accent.withValues(alpha: 0.28)),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        _isReward ? 'به حقوق اضافه می‌شود' : 'از حقوق کم می‌شود',
                        style: theme.textTheme.bodyMedium,
                      ),
                      Text(
                        MoneyFormat.plain(_worth),
                        style: theme.textTheme.titleMedium?.copyWith(
                          fontWeight: FontWeight.w800,
                          color: accent,
                          fontFeatures: const [FontFeature.tabularFigures()],
                        ),
                      ),
                    ],
                  ),
                ),
              ],

              const SizedBox(height: Gap.section),
              FilledButton.icon(
                onPressed: _canSave ? _save : null,
                icon: _saving
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.check_rounded),
                label: const Text('ثبت کن'),
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
