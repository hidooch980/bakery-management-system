import 'package:flutter/material.dart';
import '../../models/bank_account.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../utils/one_write.dart';
import '../../widgets/common.dart';

/// ثبت پرداخت واقعی با حساب مشخص و حفظ نام درخواست هنگام تلاش دوباره.
class FinancialPaymentSheet extends StatefulWidget {
  const FinancialPaymentSheet(
      {super.key,
      required this.api,
      required this.title,
      required this.accounts,
      this.employeeId,
      this.loanId,
      this.suggestedAmount = 0,
      this.maximumAmount});
  final BakeryApi api;
  final String title;
  final List<BankAccount> accounts;
  final int? employeeId, loanId;
  final double suggestedAmount;
  final double? maximumAmount;
  @override
  State<FinancialPaymentSheet> createState() => _FinancialPaymentSheetState();
}

class _FinancialPaymentSheetState extends State<FinancialPaymentSheet> {
  final _form = GlobalKey<FormState>();
  final _writes = OneWrite();
  late final TextEditingController _amount;
  final _date = TextEditingController(text: JalaliFormat.date(DateTime.now()));
  final _note = TextEditingController();
  int? _account;
  bool _saving = false;
  @override
  void initState() {
    super.initState();
    _amount = TextEditingController(
        text: widget.suggestedAmount > 0
            ? MoneyFormat.plain(widget.suggestedAmount)
            : '');
    final defaults = widget.accounts.where((a) => a.isDefault);
    _account = defaults.isEmpty ? null : defaults.first.id;
  }

  @override
  void dispose() {
    _amount.dispose();
    _date.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || !_form.currentState!.validate()) return;
    final data = <String, dynamic>{
      if (widget.employeeId != null) 'user_id': widget.employeeId,
      'amount': MoneyFormat.parseInput(_amount.text)!,
      'paid_on': latinDigits(_date.text.trim()),
      'bank_account_id': _account,
      'note': _note.text.trim(),
    };
    final intent = '${widget.employeeId}-${widget.loanId}-$data';
    setState(() => _saving = true);
    try {
      final key = _writes.nameFor(intent);
      if (widget.loanId != null) {
        await widget.api.payBankLoan(widget.loanId!, data, key);
      } else {
        await widget.api.recordStaffAdvance(data, key);
      }
      _writes.done(intent);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) showMessage(context, '$e', isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
        canPop: !_saving,
        child: Padding(
          padding: EdgeInsets.fromLTRB(
              20, 20, 20, MediaQuery.of(context).viewInsets.bottom + 24),
          child: SingleChildScrollView(
              child: Form(
                  key: _form,
                  child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(widget.title,
                            style: Theme.of(context).textTheme.titleLarge),
                        const Text(
                            'فقط پرداخت انجام‌شده را ثبت کنید؛ مبلغ از حساب انتخاب‌شده کسر می‌شود.'),
                        TextFormField(
                            controller: _amount,
                            enabled: !_saving,
                            keyboardType: TextInputType.number,
                            decoration:
                                const InputDecoration(labelText: 'مبلغ پرداخت'),
                            validator: (v) {
                              final n = MoneyFormat.parseInput(v);
                              if (n == null || n < 1) {
                                return 'مبلغ مثبت وارد کنید';
                              }
                              if (widget.maximumAmount != null &&
                                  n > widget.maximumAmount!) {
                                return 'مبلغ از مانده وام بیشتر است';
                              }
                              return null;
                            }),
                        TextFormField(
                            controller: _date,
                            enabled: !_saving,
                            decoration: const InputDecoration(
                                labelText: 'تاریخ پرداخت شمسی'),
                            validator: (v) => (v?.trim().isEmpty ?? true)
                                ? 'تاریخ پرداخت را بنویسید'
                                : null),
                        DropdownButtonFormField<int>(
                            initialValue: _account,
                            isExpanded: true,
                            decoration:
                                const InputDecoration(labelText: 'حساب پرداخت'),
                            items: [
                              for (final a in widget.accounts)
                                DropdownMenuItem(
                                    value: a.id, child: Text(a.title))
                            ],
                            onChanged: _saving
                                ? null
                                : (v) => setState(() => _account = v),
                            validator: (v) => v == null
                                ? 'حساب پرداخت را انتخاب کنید'
                                : null),
                        TextFormField(
                            controller: _note,
                            enabled: !_saving,
                            maxLength: 500,
                            decoration: const InputDecoration(
                                labelText: 'توضیح پرداخت')),
                        FilledButton(
                            onPressed: _saving ? null : _save,
                            child: Text(_saving
                                ? 'در حال ثبت…'
                                : 'ثبت پرداخت انجام‌شده')),
                        TextButton(
                            onPressed:
                                _saving ? null : () => Navigator.pop(context),
                            child: const Text('انصراف')),
                      ]))),
        ),
      );
}
