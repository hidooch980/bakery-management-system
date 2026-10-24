import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/manager_corrections_api.dart';
import '../../utils/formatters.dart';
import '../../utils/one_write.dart';
import '../../widgets/common.dart';

class SellerWorkScreen extends StatelessWidget {
  const SellerWorkScreen(
      {super.key,
      required this.api,
      required this.sellerId,
      required this.sellerName});
  final ManagerCorrectionsApi api;
  final int sellerId;
  final String sellerName;

  @override
  Widget build(BuildContext context) => DefaultTabController(
        length: 2,
        child: Scaffold(
          appBar: AppBar(
              title: Text('اصلاح کارکرد $sellerName'),
              bottom: const TabBar(tabs: [
                Tab(text: 'حضور و ساعت کار'),
                Tab(text: 'فروش و دریافتی'),
              ])),
          body: TabBarView(children: [
            _WorkList(api: api, sellerId: sellerId, kind: 'attendance'),
            _WorkList(api: api, sellerId: sellerId, kind: 'sales'),
          ]),
        ),
      );
}

class _WorkList extends StatefulWidget {
  const _WorkList(
      {required this.api, required this.sellerId, required this.kind});
  final ManagerCorrectionsApi api;
  final int sellerId;
  final String kind;
  @override
  State<_WorkList> createState() => _WorkListState();
}

class _WorkListState extends State<_WorkList> {
  int _page = 1;
  late Future<Map<String, dynamic>> _data = _load();
  Future<Map<String, dynamic>> _load() =>
      widget.api.work(widget.sellerId, widget.kind, _page);
  void _reload() => setState(() => _data = _load());
  Future<void> _edit(Map<String, dynamic>? row, String currency) async {
    final saved = await showDialog<bool>(
        context: context,
        builder: (_) => SellerWorkEditDialog(
            api: widget.api,
            sellerId: widget.sellerId,
            kind: widget.kind,
            row: row,
            currency: currency));
    if (saved == true && mounted) _reload();
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<Map<String, dynamic>>(
        future: _data,
        builder: (context, snapshot) {
          if (snapshot.connectionState != ConnectionState.done) {
            return const Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return ErrorBox(
                message: 'کارکرد دریافت نشد: ${snapshot.error}',
                onRetry: _reload);
          }
          final data = snapshot.data!;
          final rows = (data['data'] as List).cast<Map<String, dynamic>>();
          final attendance = widget.kind == 'attendance';
          return ListView(padding: const EdgeInsets.all(16), children: [
            if (attendance)
              FilledButton.icon(
                  onPressed: () => _edit(null, ''),
                  icon: const Icon(Icons.add),
                  label: const Text('ثبت حضور جامانده')),
            const Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: Text(
                    'اصلاح، رکورد اصلی و گزارش‌های مرتبط را تغییر می‌دهد. دلیل اصلاح الزامی است.')),
            for (final row in rows)
              Card(
                  child: ListTile(
                title: Text(attendance
                    ? '${row['date_label']} · ${row['in_time'] ?? '—'} تا ${row['out_time'] ?? 'خروج ثبت نشده'}'
                    : '${row['date_label']} · ${row['payment_label']}'),
                subtitle: Text(attendance
                    ? '${row['worked_minutes'] == null ? 'کارکرد نهایی نشده' : workedDuration((row['worked_minutes'] as num).toInt())}${row['correction_reason'] == null ? '' : '\nدلیل اصلاح: ${row['correction_reason']}'}'
                    : '${persianDigits('${row['bread_count']}')} نان · ${row['amount_formatted']}${row['note'] == null ? '' : '\n${row['note']}'}'),
                trailing: IconButton(
                    tooltip: 'ویرایش رکورد',
                    icon: const Icon(Icons.edit_outlined),
                    onPressed: () => _edit(row, '${data['currency_label']}')),
              )),
            if (rows.isEmpty) const Text('رکوردی ثبت نشده است.'),
            Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              TextButton(
                  onPressed: _page > 1
                      ? () {
                          _page--;
                          _reload();
                        }
                      : null,
                  child: const Text('صفحه قبل')),
              Text('صفحه ${persianDigits('$_page')}'),
              TextButton(
                  onPressed: data['next_page_url'] != null
                      ? () {
                          _page++;
                          _reload();
                        }
                      : null,
                  child: const Text('صفحه بعد')),
            ]),
          ]);
        },
      );
}

class SellerWorkEditDialog extends StatefulWidget {
  const SellerWorkEditDialog(
      {super.key,
      required this.api,
      required this.sellerId,
      required this.kind,
      this.row,
      required this.currency});
  final ManagerCorrectionsApi api;
  final int sellerId;
  final String kind, currency;
  final Map<String, dynamic>? row;
  @override
  State<SellerWorkEditDialog> createState() => _SellerWorkEditDialogState();
}

class _SellerWorkEditDialogState extends State<SellerWorkEditDialog> {
  final _form = GlobalKey<FormState>();
  final _writes = OneWrite();
  final _reason = TextEditingController();
  late final _date = TextEditingController(
      text: widget.row == null
          ? JalaliFormat.date(DateTime.now())
          : JalaliFormat.date(DateTime.tryParse('${widget.row!['date']}')));
  late final _first = TextEditingController(
      text: widget.kind == 'attendance'
          ? '${widget.row?['in_time'] ?? ''}'
          : '${widget.row?['bread_count'] ?? 0}');
  late final _second = TextEditingController(
      text: widget.kind == 'attendance'
          ? '${widget.row?['out_time'] ?? ''}'
          : '${widget.row?['amount'] ?? 0}');
  late bool _nextDay = widget.row?['out_next_day'] == true;
  bool _busy = false;
  String? _error;
  bool get _attendance => widget.kind == 'attendance';
  bool get _takesMoney => widget.row?['takes_money'] == true;

  @override
  void dispose() {
    for (final c in [_reason, _date, _first, _second]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy || !_form.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final data = <String, dynamic>{
      if (widget.row != null) 'version': widget.row!['version'],
      'reason': _reason.text.trim(),
      if (_attendance) ...{
        'date': latinDigits(_date.text.trim()),
        'in_time': latinDigits(_first.text.trim()),
        'out_time': _second.text.trim().isEmpty
            ? null
            : latinDigits(_second.text.trim()),
        'out_next_day': _nextDay,
      } else ...{
        'bread_count': MoneyFormat.parseInput(_first.text)!.toInt(),
        'amount': _takesMoney ? MoneyFormat.parseInput(_second.text)! : 0,
        'currency_label': widget.currency,
      },
    };
    final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
              title: const Text('تأیید اصلاح کارکرد'),
              content: SingleChildScrollView(
                  child: Text(_attendance
                      ? 'تاریخ: ${_date.text}\nورود: ${_first.text}\nخروج: ${_second.text.isEmpty ? 'ثبت نشده' : _second.text}${_nextDay ? ' (روز بعد)' : ''}\nدلیل: ${_reason.text.trim()}'
                      : 'تعداد نان: ${widget.row!['bread_count']} ← ${_first.text}\nمبلغ: ${widget.row!['amount_formatted']} ← ${_takesMoney ? _second.text : '۰'} ${widget.currency}\nدلیل: ${_reason.text.trim()}')),
              actions: [
                TextButton(
                    onPressed: () => Navigator.pop(context, false),
                    child: const Text('بازگشت')),
                FilledButton(
                    onPressed: () => Navigator.pop(context, true),
                    child: const Text('ثبت اصلاح'))
              ],
            ));
    if (!mounted) return;
    if (confirmed != true) {
      setState(() => _busy = false);
      return;
    }
    try {
      await widget.api.correctWork(widget.sellerId, widget.kind,
          widget.row?['id'] as int?, data, _writes.nameFor(data.toString()));
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = e is ApiException
              ? e.message
              : 'اصلاح تأیید نشد؛ رکورد را دوباره بررسی کنید.';
        });
      }
    }
  }

  String? _time(String? value, {bool optional = false}) {
    final text = latinDigits(value?.trim() ?? '');
    if (optional && text.isEmpty) return null;
    return RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(text)
        ? null
        : 'ساعت را مانند ۰۷:۳۰ وارد کنید';
  }

  @override
  Widget build(BuildContext context) => PopScope(
      canPop: !_busy,
      child: AlertDialog(
        title: Text(_attendance
            ? (widget.row == null ? 'ثبت حضور جامانده' : 'ویرایش حضور')
            : 'ویرایش فروش و دریافتی'),
        content: SingleChildScrollView(
            child: Form(
                key: _form,
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  if (_attendance)
                    TextFormField(
                        controller: _date,
                        readOnly: widget.row != null,
                        decoration: const InputDecoration(
                            labelText: 'تاریخ (۱۴۰۵/۰۸/۰۱)'),
                        validator: (v) => (v?.trim().isEmpty ?? true)
                            ? 'تاریخ را وارد کنید'
                            : null),
                  TextFormField(
                      controller: _first,
                      enabled: !_busy,
                      decoration: InputDecoration(
                          labelText: _attendance ? 'ساعت ورود' : 'تعداد نان'),
                      validator: (v) {
                        if (_attendance) return _time(v);
                        final n = MoneyFormat.parseInput(v);
                        return n == null ||
                                !n.isFinite ||
                                n < 0 ||
                                n > 1000000 ||
                                n != n.roundToDouble()
                            ? 'تعداد صحیح نامنفی وارد کنید'
                            : null;
                      }),
                  TextFormField(
                      controller: _second,
                      enabled: !_busy && (_attendance || _takesMoney),
                      decoration: InputDecoration(
                          labelText: _attendance
                              ? 'ساعت خروج (خالی: ثبت نشده)'
                              : 'مبلغ ${widget.currency}'),
                      validator: (v) {
                        if (_attendance) return _time(v, optional: true);
                        if (!_takesMoney) return null;
                        final n = MoneyFormat.parseInput(v);
                        return n == null || !n.isFinite || n < 0
                            ? 'مبلغ نامنفی وارد کنید'
                            : null;
                      }),
                  if (_attendance)
                    CheckboxListTile(
                        title: const Text('خروج در روز بعد است'),
                        value: _nextDay,
                        onChanged: _busy
                            ? null
                            : (v) => setState(() => _nextDay = v ?? false)),
                  TextFormField(
                      controller: _reason,
                      enabled: !_busy,
                      maxLength: 250,
                      decoration: const InputDecoration(
                          labelText: 'دلیل اصلاح (الزامی)'),
                      validator: (v) => (v?.trim().length ?? 0) < 3
                          ? 'دلیل اصلاح را بنویسید'
                          : null),
                  if (_error != null)
                    Text(_error!,
                        style: TextStyle(
                            color: Theme.of(context).colorScheme.error)),
                ]))),
        actions: [
          TextButton(
              onPressed: _busy ? null : () => Navigator.pop(context),
              child: const Text('انصراف')),
          FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(_busy ? 'در حال ثبت…' : 'بررسی اصلاح'))
        ],
      ));
}
