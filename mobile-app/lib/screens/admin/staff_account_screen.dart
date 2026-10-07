import 'package:flutter/material.dart';

import '../../models/payroll.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../utils/json.dart';
import '../../widgets/common.dart';
import 'adjustment_sheet.dart';

/// پروندهٔ مالی کارمند با دسترسی به رکورد اصلی و فیش‌های مرتبط.
class StaffAccountScreen extends StatefulWidget {
  const StaffAccountScreen(
      {super.key, required this.api, required this.person});
  final BakeryApi api;
  final Employee person;

  @override
  State<StaffAccountScreen> createState() => _StaffAccountScreenState();
}

class _StaffAccountScreenState extends State<StaffAccountScreen> {
  late Future<Map<String, dynamic>> _future;
  Map<String, dynamic> _data = {};

  @override
  void initState() {
    super.initState();
    _future = widget.api.staffAccount(widget.person.id);
  }

  void _reload() =>
      setState(() => _future = widget.api.staffAccount(widget.person.id));

  String _label(String kind) => switch (kind) {
        'salary' => 'فیش حقوق',
        'advance' => 'مساعده',
        'bread' => 'نان منزل',
        _ => 'تشویقی و کسورات',
      };

  List<Map<String, dynamic>> _rows(String kind) => rowList(_data[switch (kind) {
        'salary' => 'payslips',
        'advance' => 'advances',
        'bread' => 'bread',
        _ => 'adjustments',
      }]);

  Future<void> _linked(String kind, int id) async {
    final matches = _rows(kind).where((r) => r['id'] == id);
    if (matches.isEmpty) {
      showMessage(context, 'رکورد مرتبط در ۱۰۰ مورد اخیر نیست.', isError: true);
      return;
    }
    await _detail(kind, matches.first);
  }

  Future<void> _edit(String kind, Map<String, dynamic> row) async {
    final changes = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _EntryEditDialog(
          kind: kind,
          row: row,
          summary: keyedGroup(_data['summary']),
          currencyLabel: '${_data['currency_label'] ?? ''}'),
    );
    if (changes == null || !mounted) return;
    try {
      if (kind == 'salary') {
        await widget.api.updatePayslip(row['id'] as int, changes);
      } else {
        await widget.api.updateStaffEntry(kind, row['id'] as int, changes);
      }
      if (!mounted) return;
      showMessage(context, 'رکورد اصلی و مانده حساب اصلاح شد.');
      _reload();
    } catch (e) {
      if (mounted) showMessage(context, '$e', isError: true);
    }
  }

  Future<void> _detail(String kind, Map<String, dynamic> row) async {
    await showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
              title: Text('${_label(kind)} #${row['id']}'),
              content: SingleChildScrollView(
                  child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (kind == 'salary') ...[
                    Text('دوره: ${row['period_label']}'),
                    Text(
                        'حقوق پایه: ${MoneyFormat.plain(_number(row['base_amount']))}'),
                    Text('پاداش: ${MoneyFormat.plain(_number(row['bonus']))}'),
                    Text(
                        'کسورات: ${MoneyFormat.plain(_number(row['deduction']))}'),
                    Text('کسر مساعده: ${row['advance_deduction_formatted']}'),
                    Text('کسر نان: ${row['bread_deduction_formatted']}'),
                    Text('خالص: ${row['net_amount_formatted']}'),
                    Text(row['is_paid'] == true
                        ? 'پرداخت: ${row['paid_on_jalali']}'
                        : 'پرداخت نشده'),
                    Text('حساب: ${row['bank_account_title'] ?? 'صندوق'}'),
                    for (final link in rowList(row['advance_links']))
                      ListTile(
                          title: Text('مساعده #${link['id']}'),
                          subtitle: Text('${link['amount']}'),
                          trailing: const Icon(Icons.open_in_new),
                          onTap: () {
                            Navigator.pop(dialogContext);
                            _linked('advance', link['id'] as int);
                          }),
                    for (final link in rowList(row['bread_links']))
                      ListTile(
                          title: Text('نان منزل #${link['id']}'),
                          subtitle: Text('${link['amount']}'),
                          trailing: const Icon(Icons.open_in_new),
                          onTap: () {
                            Navigator.pop(dialogContext);
                            _linked('bread', link['id'] as int);
                          }),
                  ] else ...[
                    Text('تاریخ: ${row['date']}'),
                    if (kind == 'bread')
                      Text('تعداد: ${row['bread_count']} نان'),
                    Text('مبلغ: ${row['amount_formatted']}'),
                    if (row['outstanding_formatted'] != null)
                      Text('مانده: ${row['outstanding_formatted']}'),
                    if (kind == 'adjustment')
                      Text(row['kind'] == 'reward' ? 'تشویقی' : 'کسورات'),
                    if (row['waived'] == true)
                      const Text('بخشیده شده؛ در حقوق محاسبه نمی‌شود'),
                    for (final id in _salaryIds(row['salary_ids']))
                      ListTile(
                          title: Text('فیش مرتبط #$id'),
                          trailing: const Icon(Icons.open_in_new),
                          onTap: () {
                            Navigator.pop(dialogContext);
                            _linked('salary', id);
                          }),
                    if (row['editable'] != true)
                      const Text(
                          'برای مورد تسویه‌شده، ابتدا فیش مرتبط را اصلاح کنید. کسورات خودکار از بخش تأخیر مدیریت می‌شوند.'),
                  ],
                  if ('${row['note'] ?? ''}'.isNotEmpty)
                    Text('توضیح: ${row['note']}'),
                ],
              )),
              actions: [
                TextButton(
                    onPressed: () => Navigator.pop(dialogContext),
                    child: const Text('بستن')),
                if (kind == 'salary' || row['editable'] == true)
                  FilledButton(
                      onPressed: () {
                        Navigator.pop(dialogContext);
                        _edit(kind, row);
                      },
                      child: const Text('اصلاح رکورد اصلی')),
              ],
            ));
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
            title: Text('پرونده ${widget.person.displayName}'),
            actions: [
              IconButton(
                  onPressed: _reload,
                  icon: const Icon(Icons.refresh),
                  tooltip: 'خواندن دوباره حساب'),
            ]),
        body: FutureBuilder<Map<String, dynamic>>(
            future: _future,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
              }
              _data = snapshot.data!;
              final summary = keyedGroup(_data['summary']);
              return RefreshIndicator(
                  onRefresh: () async {
                    _reload();
                    await _future;
                  },
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(16),
                    children: [
                      Card(
                          child: Padding(
                              padding: const EdgeInsets.all(16),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.stretch,
                                children: [
                                  Text(
                                      'حقوق پرداخت‌نشده: ${summary['unpaid']}'),
                                  Text('مانده مساعده: ${summary['advances']}'),
                                  Text('مانده بدهی نان: ${summary['bread']}'),
                                  const SizedBox(height: 8),
                                  const Text(
                                      'مانده بدهی با حقوق پرداخت‌نشده یکی نیست؛ بدهی فقط طبق انتخاب مدیر در فیش کسر می‌شود.'),
                                ],
                              ))),
                      OutlinedButton.icon(
                          onPressed: () async {
                            final saved = await showModalBottomSheet<bool>(
                                context: context,
                                isScrollControlled: true,
                                builder: (_) => AdjustmentSheet(
                                    api: widget.api, staff: [widget.person]));
                            if (saved == true && mounted) _reload();
                          },
                          icon: const Icon(Icons.add),
                          label: const Text(
                              'ثبت تشویقی یا کسورات برای این کارمند')),
                      for (final kind in [
                        'salary',
                        'advance',
                        'bread',
                        'adjustment'
                      ])
                        Card(
                            child: ExpansionTile(
                                initiallyExpanded: kind == 'salary',
                                title: Text(_label(kind)),
                                subtitle:
                                    Text('${_rows(kind).length} رکورد اخیر'),
                                children: [
                              if (_rows(kind).isEmpty)
                                const ListTile(title: Text('رکوردی ثبت نشده')),
                              for (final row in _rows(kind))
                                ListTile(
                                  title: Text(kind == 'salary'
                                      ? '${row['period_label']}'
                                      : '${_label(kind)} #${row['id']}'),
                                  subtitle: Text(kind == 'salary'
                                      ? '${row['is_paid'] == true ? 'پرداخت شده' : 'پرداخت نشده'} • ${row['net_amount_formatted']}'
                                      : '${row['date']} • ${row['outstanding_formatted'] ?? row['amount_formatted']}'),
                                  trailing: const Icon(Icons.chevron_left),
                                  onTap: () => _detail(kind, row),
                                ),
                            ])),
                      const Text(
                          'ریز هر بخش حداکثر ۱۰۰ رکورد اخیر است؛ مانده‌های بالا از کل حساب محاسبه می‌شوند.'),
                    ],
                  ));
            }),
      );
}

// فهرست شناسه‌ها بدون cast شکننده، حتی اگر سرور مجموعهٔ کلیددار بدهد.
List<int> _salaryIds(dynamic value) {
  final values = value is List
      ? value
      : value is Map
          ? value.values
          : const [];
  return values.whereType<num>().map((id) => id.toInt()).toList();
}

double _number(dynamic value) => double.tryParse('$value') ?? 0;

class _EntryEditDialog extends StatefulWidget {
  const _EntryEditDialog(
      {required this.kind,
      required this.row,
      required this.summary,
      required this.currencyLabel});
  final String kind;
  final Map<String, dynamic> row;
  final Map<String, dynamic> summary;
  final String currencyLabel;
  @override
  State<_EntryEditDialog> createState() => _EntryEditDialogState();
}

class _EntryEditDialogState extends State<_EntryEditDialog> {
  final _form = GlobalKey<FormState>();
  final _fields = <String, TextEditingController>{};
  late final TextEditingController _note;
  bool _advance = true, _bread = true;

  @override
  void initState() {
    super.initState();
    for (final name in widget.kind == 'salary'
        ? ['base_amount', 'bonus', 'deduction']
        : [widget.kind == 'bread' ? 'bread_count' : 'amount']) {
      _fields[name] = TextEditingController(text: '${widget.row[name] ?? 0}')
        ..addListener(() => setState(() {}));
    }
    _note = TextEditingController();
    _advance = widget.row['recover_advances'] != false;
    _bread = widget.row['recover_bread'] != false;
  }

  double get _gross =>
      (MoneyFormat.parseInput(_fields['base_amount']?.text) ?? 0) +
      (MoneyFormat.parseInput(_fields['bonus']?.text) ?? 0) -
      (MoneyFormat.parseInput(_fields['deduction']?.text) ?? 0);

  double get _net {
    final available = _gross < 0 ? 0.0 : _gross;
    final advanceOwed = _number(widget.summary['advance_outstanding']) +
        _number(widget.row['advance_deduction']);
    final advance =
        !_advance ? 0.0 : (advanceOwed < available ? advanceOwed : available);
    final left = available - advance;
    final breadOwed = _number(widget.summary['bread_outstanding']) +
        _number(widget.row['bread_deduction']);
    final bread = !_bread ? 0.0 : (breadOwed < left ? breadOwed : left);
    return left - bread;
  }

  @override
  void dispose() {
    for (final c in _fields.values) {
      c.dispose();
    }
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('اصلاح رکورد اصلی'),
        content: SingleChildScrollView(
            child: Form(
                key: _form,
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  if (widget.kind == 'salary' && widget.row['is_paid'] == true)
                    const Text(
                        'اصلاح فیش پرداخت‌شده، خالص حقوق، گردش حساب و تسویه بدهی‌های مرتبط را تغییر می‌دهد.'),
                  for (final entry in _fields.entries)
                    TextFormField(
                        controller: entry.value,
                        keyboardType: TextInputType.number,
                        decoration: InputDecoration(
                            labelText: switch (entry.key) {
                          'base_amount' => 'حقوق پایه',
                          'bonus' => 'پاداش',
                          'deduction' => 'کسورات',
                          'bread_count' => 'تعداد نان',
                          _ => 'مبلغ',
                        }),
                        validator: (text) {
                          final value = MoneyFormat.parseInput(text);
                          if (value == null || value < 0) {
                            return 'عدد نامنفی وارد کنید';
                          }
                          if (entry.key == 'bread_count' &&
                              (value < 1 || value != value.roundToDouble())) {
                            return 'تعداد صحیح و مثبت وارد کنید';
                          }
                          return null;
                        }),
                  if (widget.kind == 'salary') ...[
                    CheckboxListTile(
                        title: const Text('کسر مساعده'),
                        value: _advance,
                        onChanged: (v) =>
                            setState(() => _advance = v ?? false)),
                    CheckboxListTile(
                        title: const Text('کسر بدهی نان'),
                        value: _bread,
                        onChanged: (v) => setState(() => _bread = v ?? false)),
                  ],
                  if (widget.kind == 'salary')
                    Text(
                        'خالص پس از اصلاح: ${MoneyFormat.plain(_net)} ${widget.currencyLabel}'),
                  TextFormField(
                      controller: _note,
                      maxLength: 250,
                      decoration: const InputDecoration(
                          labelText: 'دلیل اصلاح (الزامی)'),
                      validator: (v) => (v?.trim().length ?? 0) < 3
                          ? 'دلیل اصلاح را بنویسید'
                          : null),
                ]))),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('انصراف')),
          FilledButton(
              onPressed: () {
                if (!_form.currentState!.validate()) return;
                final data = <String, dynamic>{
                  for (final e in _fields.entries)
                    e.key: e.key == 'bread_count'
                        ? MoneyFormat.parseInput(e.value.text)!.toInt()
                        : MoneyFormat.parseInput(e.value.text)!
                };
                if (widget.kind == 'salary') {
                  if (_number(data['deduction']) >
                      _number(data['base_amount']) + _number(data['bonus'])) {
                    showMessage(context, 'کسورات از حقوق و پاداش بیشتر است.',
                        isError: true);
                    return;
                  }
                  data['recover_advances'] = _advance;
                  data['recover_bread'] = _bread;
                }
                data['note'] = 'اصلاح: ${_note.text.trim()}';
                Navigator.pop(context, data);
              },
              child: const Text('تأیید اصلاح')),
        ],
      );
}
