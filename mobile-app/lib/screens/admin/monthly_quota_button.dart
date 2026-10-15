import 'package:flutter/material.dart';
import 'package:shamsi_date/shamsi_date.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// ثبت سهمیه از همان صفحه سوخت؛ ثبت تحویل مستقل از آن می‌ماند.
class MonthlyQuotaButton extends StatelessWidget {
  const MonthlyQuotaButton({
    super.key,
    required this.api,
    required this.onSaved,
  });

  final BakeryApi api;
  final VoidCallback onSaved;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(14),
      child: FilledButton.tonalIcon(
        icon: const Icon(Icons.calendar_month_rounded),
        label: const Text('ثبت سهمیه ماهانه'),
        onPressed: () async {
          final saved = await showModalBottomSheet<bool>(
            context: context,
            isScrollControlled: true,
            isDismissible: false,
            enableDrag: false,
            builder: (_) => _QuotaSheet(api: api),
          );
          if (!context.mounted || saved != true) return;
          onSaved();
          showMessage(context, 'سهمیه ماهانه ثبت شد؛ سهمیه گازوئیل از سهمیه آرد محاسبه می‌شود.');
        },
      ),
    );
  }
}

class _QuotaSheet extends StatefulWidget {
  const _QuotaSheet({required this.api});
  final BakeryApi api;

  @override
  State<_QuotaSheet> createState() => _QuotaSheetState();
}

class _QuotaSheetState extends State<_QuotaSheet> {
  final _form = GlobalKey<FormState>();
  final _bags = TextEditingController();
  final _carryover = TextEditingController();
  final _note = TextEditingController();
  late final Map<String, String> _months;
  late String _month;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    // روزهای اول تا چهارم هنوز در چرخه سهمیه ماه قبل‌اند؛ ماه قابل تغییر است.
    final base = Jalali.fromDateTime(
      DateTime.now().subtract(const Duration(days: 4)),
    );
    _months = {};
    for (var offset = -12; offset <= 12; offset++) {
      final index = base.year * 12 + base.month - 1 + offset;
      final date = Jalali(index ~/ 12, index % 12 + 1, 1);
      final key = '${date.year}/${date.month.toString().padLeft(2, '0')}/01';
      _months[key] = JalaliFormat.monthLabel(date.toDateTime());
      if (offset == 0) _month = key;
    }
  }

  @override
  void dispose() {
    _bags.dispose();
    _carryover.dispose();
    _note.dispose();
    super.dispose();
  }

  double? _number(String text) => double.tryParse(
        latinDigits(text.trim())
            .replaceAll(',', '')
            .replaceAll('٬', '')
            .replaceAll('٫', '.'),
      );

  String? _validate(String? text, {bool optional = false}) {
    if (optional && (text ?? '').trim().isEmpty) return null;
    final value = _number(text ?? '');
    return value == null || !value.isFinite || value < 0
        ? 'تعداد کیسه معتبر و نامنفی وارد کنید.'
        : null;
  }

  Future<void> _save() async {
    if (_saving || !_form.currentState!.validate()) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.api.setFlourQuota(
        monthStart: _month,
        totalBags: _number(_bags.text)!,
        carryoverBags: _number(_carryover.text),
        note: _note.text.trim(),
      );
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) {
        setState(() => _error = 'ثبت سهمیه انجام نشد؛ اتصال را بررسی و دوباره تلاش کنید.');
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: !_saving,
      child: SafeArea(
        child: Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20,
              MediaQuery.of(context).viewInsets.bottom + 20),
          child: SingleChildScrollView(
            child: Form(
              key: _form,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('ثبت سهمیه ماهانه',
                      style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 12),
                  const Text('سهمیه آرد به کیسه ثبت و میان سه دوره تقسیم می‌شود. '
                      'سهمیه گازوئیل با نرخ موجود نانوایی محاسبه می‌شود.'),
                  const SizedBox(height: 16),
                  DropdownButtonFormField<String>(
                    key: const ValueKey('quota-month'),
                    initialValue: _month,
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'ماه سهمیه'),
                    items: [
                      for (final month in _months.entries)
                        DropdownMenuItem(value: month.key, child: Text(month.value)),
                    ],
                    onChanged: _saving ? null : (value) => setState(() => _month = value!),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    key: const ValueKey('quota-bags'),
                    controller: _bags,
                    enabled: !_saving,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'سهمیه ماه (کیسه)'),
                    validator: _validate,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    key: const ValueKey('quota-carryover'),
                    controller: _carryover,
                    enabled: !_saving,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'مانده از قبل (کیسه، اختیاری)'),
                    validator: (text) => _validate(text, optional: true),
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    key: const ValueKey('quota-note'),
                    controller: _note,
                    enabled: !_saving,
                    maxLength: 500,
                    decoration: const InputDecoration(labelText: 'توضیحات (اختیاری)'),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 8),
                    Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  ],
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: _saving ? null : _save,
                    child: Text(_saving ? 'در حال ثبت…' : 'ثبت سهمیه'),
                  ),
                  TextButton(
                    onPressed: _saving ? null : () => Navigator.pop(context, false),
                    child: const Text('انصراف'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
