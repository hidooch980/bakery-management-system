import 'package:flutter/material.dart';

import '../../models/quota_and_advance.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

typedef _DieselData = ({DieselQuota? quota, List<DieselDelivery> deliveries});

/// The month's diesel quota, and the tankers drawn against it.
///
/// Recording used to mean carrying the docket back to a desk, so it is here
/// instead: the litres go in beside the lorry, and if the tanker takes the
/// month past its quota that is said then rather than at month end, while
/// there is still time to do something about it.
class DieselSection extends StatefulWidget {
  const DieselSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<DieselSection> createState() => _DieselSectionState();
}

class _DieselSectionState extends State<DieselSection> {
  late Future<_DieselData> _data;

  @override
  void initState() {
    super.initState();
    _data = widget.api.dieselQuota();
  }

  void _reload() => setState(() => _data = widget.api.dieselQuota());

  Future<void> _recordDelivery() async {
    final result = await showModalBottomSheet<_DeliveryInput>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _DeliverySheet(),
    );

    if (result == null) return;

    try {
      final outcome = await widget.api.recordDieselDelivery(
        litres: result.litres,
        amount: result.amount,
        docketNumber: result.docket,
      );

      if (!mounted) return;

      showMessage(
        context,
        outcome.warning ?? 'تحویل ثبت شد.',
        isError: outcome.warning != null,
      );
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _changeRate(DieselQuota quota) async {
    final litres = await showDialog<double>(
      context: context,
      builder: (_) => _RateDialog(current: quota.litresPerBag ?? 0),
    );

    if (litres == null) return;

    try {
      await widget.api.setDieselRate(litresPerBag: litres);
      if (!mounted) return;
      showMessage(context, 'نرخ این ماه و ماه‌های بعد به‌روزرسانی شد.');
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<_DieselData>(
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

        final quota = snapshot.data!.quota;
        final deliveries = snapshot.data!.deliveries;

        // No quota registered is a fact, not a zero. Showing 0 litres left
        // would read as an empty tank rather than an unanswered question.
        if (quota == null) {
          return const AdminSection(
            title: 'سهمیه گازوئیل',
            icon: Icons.local_gas_station_rounded,
            children: [
              AdminRow(
                label: 'وضعیت',
                value: 'برای این ماه سهمیه‌ای ثبت نشده',
              ),
              AdminRow(
                label: 'راه‌حل',
                value: 'اول سهمیه آرد ماه را وارد کنید',
              ),
            ],
          );
        }

        return AdminSection(
          title: 'سهمیه گازوئیل — ${quota.monthLabel}',
          icon: Icons.local_gas_station_rounded,
          trailing: TextButton.icon(
            onPressed: _recordDelivery,
            icon: const Icon(Icons.add_rounded, size: IconSize.row),
            label: const Text('ثبت تحویل'),
          ),
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 4, 14, 0),
              child: _QuotaMeter(quota: quota),
            ),
            AdminRow(
              label: 'سهمیه ماه',
              value: '${_litres(quota.totalLitres)} لیتر',
            ),
            AdminRow(
              label: 'تحویل گرفته',
              value: '${_litres(quota.deliveredLitres)} لیتر',
            ),
            AdminRow(
              label: 'مانده سهمیه',
              value: quota.isOverdrawn
                  ? '${_litres(quota.remainingLitres.abs())} لیتر بیش از سهمیه'
                  : '${_litres(quota.remainingLitres)} لیتر',
            ),
            if (quota.derivationLabel != null)
              AdminRow(label: 'محاسبه سهمیه', value: quota.derivationLabel!),
            const Divider(height: 20),
            // What the depot will still issue and what is actually in the
            // tank are different questions, and only the second one stops
            // the oven — so it is labelled rather than left to be inferred.
            AdminRow(
              label: 'پخت این ماه',
              value: '${_litres(quota.bagsBaked)} کیسه'
                  ' · ${_litres(quota.consumedLitres)} لیتر سوخته',
            ),
            AdminRow(
              label: 'مانده باک (تخمینی)',
              value: quota.isTankEmpty
                  ? 'تمام شده'
                  : '${_litres(quota.inTankLitres)} لیتر',
            ),
            if (quota.isTankEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
                child: Text(
                  quota.remainingLitres > 0
                      ? 'سوخت تحویلی تمام شده، ولی '
                          '${_litres(quota.remainingLitres)} لیتر از سهمیه مانده — '
                          'تحویل بعدی را هماهنگ کنید.'
                      : 'هم سوخت تحویلی و هم سهمیه‌ی ماه تمام شده.',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: AppColors.moneyOut,
                        fontWeight: FontWeight.w600,
                      ),
                ),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 4, 14, 0),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton.icon(
                  onPressed: () => _changeRate(quota),
                  icon: const Icon(Icons.tune_rounded, size: IconSize.row),
                  label: const Text('تغییر لیتر هر کیسه'),
                ),
              ),
            ),
            if (deliveries.isNotEmpty) ...[
              const Divider(height: 24),
              for (final delivery in deliveries.take(6))
                AdminRow(
                  label: '${delivery.receivedOnLabel}'
                      '${delivery.docketNumber == null ? '' : ' · ${delivery.docketNumber}'}',
                  value: '${_litres(delivery.litres)} لیتر'
                      ' · ${delivery.amountFormatted}',
                ),
            ],
          ],
        );
      },
    );
  }

  /// Whole litres where the figure is whole — the depot issues them that
  /// way, so a trailing ".0" is noise.
  static String _litres(double value) =>
      value == value.roundToDouble() ? '${value.round()}' : '$value';
}

class _QuotaMeter extends StatelessWidget {
  const _QuotaMeter({required this.quota});

  final DieselQuota quota;

  @override
  Widget build(BuildContext context) {
    final fraction = (quota.usedPercent / 100).clamp(0.0, 1.0);

    // Straight off the ember ramp: how far along the quota is *is* how hot
    // the bar reads, so the proportion is legible before the number is.
    // Overdrawn leaves the ramp entirely — past the end of the scale is a
    // different kind of fact, not a hotter shade of the same one.
    final colour = quota.isOverdrawn
        ? Theme.of(context).colorScheme.error
        : AppColors.emberAt(fraction);

    // The ramp is for the bar, where length and shade say the same thing
    // twice and neither has to be read. It must not colour the sentence
    // under it: a ramp runs dark to light by definition, so one end of it
    // is always invisible against a given ground — the dull end at 2.2:1
    // on the night ground, the pale end at 1.1:1 in daylight. Whichever
    // way the quota is going, the words about it stay readable.
    final wordsColour = quota.isOverdrawn
        ? Theme.of(context).colorScheme.error
        : (fraction >= 0.8 ? AppColors.attention : null);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(
            value: fraction,
            minHeight: 10,
            backgroundColor: Theme.of(context).dividerColor.withValues(alpha: 0.4),
            valueColor: AlwaysStoppedAnimation(colour),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          quota.isOverdrawn
              ? 'سهمیه این ماه تمام شده'
              : '${quota.usedPercent.round()}٪ مصرف شده',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: wordsColour,
                fontWeight: FontWeight.w700,
              ),
        ),
      ],
    );
  }
}

typedef _DeliveryInput = ({double litres, double? amount, String? docket});

class _DeliverySheet extends StatefulWidget {
  const _DeliverySheet();

  @override
  State<_DeliverySheet> createState() => _DeliverySheetState();
}

class _DeliverySheetState extends State<_DeliverySheet> {
  final _litres = TextEditingController();
  final _amount = TextEditingController();
  final _docket = TextEditingController();

  @override
  void dispose() {
    _litres.dispose();
    _amount.dispose();
    _docket.dispose();
    super.dispose();
  }

  void _submit() {
    final litres = double.tryParse(_litres.text.trim());

    if (litres == null || litres <= 0) {
      showMessage(context, 'مقدار لیتر را وارد کنید.', isError: true);

      return;
    }

    Navigator.pop(context, (
      litres: litres,
      amount: double.tryParse(_amount.text.trim()),
      docket: _docket.text.trim().isEmpty ? null : _docket.text.trim(),
    ));
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
            'ثبت تحویل گازوئیل',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _litres,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            autofocus: true,
            decoration: const InputDecoration(
              labelText: 'لیتر',
              prefixIcon: Icon(Icons.water_drop_rounded),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _amount,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'مبلغ',
              // Quota fuel carries no invoice, so this is left empty more
              // often than it is filled.
              helperText: 'اگر سهمیه‌ای بوده خالی بگذارید',
              prefixIcon: Icon(Icons.payments_rounded),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _docket,
            decoration: const InputDecoration(
              labelText: 'شماره حواله',
              prefixIcon: Icon(Icons.receipt_long_rounded),
            ),
          ),
          const SizedBox(height: 20),
          FilledButton(onPressed: _submit, child: const Text('ثبت')),
        ],
      ),
    );
  }
}

class _RateDialog extends StatefulWidget {
  const _RateDialog({required this.current});

  final double current;

  @override
  State<_RateDialog> createState() => _RateDialogState();
}

class _RateDialogState extends State<_RateDialog> {
  late final _controller = TextEditingController(
    text: widget.current == widget.current.roundToDouble()
        ? '${widget.current.round()}'
        : '${widget.current}',
  );

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('لیتر هر کیسه'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const Text(
            'سهمیه این ماه دوباره حساب می‌شود و این نرخ پیش‌فرض '
            'ماه‌های بعد هم می‌شود. ماه‌های گذشته دست نمی‌خورند.',
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _controller,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            autofocus: true,
            decoration: const InputDecoration(suffixText: 'لیتر'),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('انصراف'),
        ),
        FilledButton(
          onPressed: () {
            final value = double.tryParse(_controller.text.trim());

            if (value == null || value <= 0) return;

            Navigator.pop(context, value);
          },
          child: const Text('ثبت'),
        ),
      ],
    );
  }
}
