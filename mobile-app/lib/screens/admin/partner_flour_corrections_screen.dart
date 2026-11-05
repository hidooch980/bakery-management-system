import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/manager_corrections_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

class PartnerFlourCorrectionsScreen extends StatefulWidget {
  const PartnerFlourCorrectionsScreen({super.key, required this.api});
  final ManagerCorrectionsApi api;
  @override
  State<PartnerFlourCorrectionsScreen> createState() =>
      _PartnerFlourCorrectionsScreenState();
}

class _PartnerFlourCorrectionsScreenState
    extends State<PartnerFlourCorrectionsScreen> {
  int _page = 1;
  bool _opening = false;
  late Future<Map<String, dynamic>> _data = widget.api.partnerRecords(_page);
  void _reload() => setState(() => _data = widget.api.partnerRecords(_page));
  Future<void> _edit(int id) async {
    if (_opening) return;
    setState(() => _opening = true);
    try {
      final row = await widget.api.partnerFlour(id);
      final partners = await widget.api.partners();
      if (!mounted) return;
      final saved = await showDialog<bool>(
          context: context,
          builder: (_) => PartnerFlourEditDialog(
              api: widget.api, row: row, partners: partners));
      if (saved == true && mounted) _reload();
    } catch (e) {
      if (mounted) {
        showMessage(context,
            e is ApiException ? e.message : 'ثبت آرد برای اصلاح دریافت نشد.',
            isError: true);
      }
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('اصلاح آرد همکاران')),
        body: FutureBuilder<Map<String, dynamic>>(
            future: _data,
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              if (snapshot.hasError) {
                return ErrorBox(
                    message: 'ثبت‌های آرد دریافت نشد.', onRetry: _reload);
              }
              final data = snapshot.data!;
              final rows = (data['data'] as List).cast<Map<String, dynamic>>();
              return ListView(padding: const EdgeInsets.all(16), children: [
                const Text(
                    'برای تصحیح اشتباه، ثبت اصلی را ویرایش کنید. پیش از ذخیره، همکار، مقدار و جهت را دوباره بررسی کنید.'),
                for (final row in rows)
                  Card(
                      child: ListTile(
                    title: Text(
                        '${row['partner_name']} · ${row['quantity_label']}'),
                    subtitle: Text(
                        '${row['direction_label']} · ${row['occurred_on_display']}${row['note'] == null ? '' : '\n${row['note']}'}'),
                    trailing: IconButton(
                        tooltip: 'اصلاح ثبت آرد',
                        onPressed:
                            _opening ? null : () => _edit(row['id'] as int),
                        icon: const Icon(Icons.edit_outlined)),
                  )),
                if (rows.isEmpty) const Text('ثبت آردی وجود ندارد.'),
                Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
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
            }),
      );
}

class PartnerFlourEditDialog extends StatefulWidget {
  const PartnerFlourEditDialog(
      {super.key,
      required this.api,
      required this.row,
      required this.partners});
  final ManagerCorrectionsApi api;
  final Map<String, dynamic> row;
  final List<Map<String, dynamic>> partners;
  @override
  State<PartnerFlourEditDialog> createState() => _PartnerFlourEditDialogState();
}

class _PartnerFlourEditDialogState extends State<PartnerFlourEditDialog> {
  final _form = GlobalKey<FormState>();
  final _reason = TextEditingController();
  late final _bags = TextEditingController(text: '${widget.row['bags']}');
  late final _date = TextEditingController(
      text: JalaliFormat.date(DateTime.tryParse('${widget.row['date']}')));
  late final _name =
      TextEditingController(text: '${widget.row['partner_name']}');
  late String _direction = '${widget.row['direction']}';
  late int _partner = (widget.row['customer_id'] as num?)?.toInt() ?? 0;
  bool _busy = false;
  String? _error;
  @override
  void dispose() {
    for (final c in [_reason, _bags, _date, _name]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    if (_busy || !_form.currentState!.validate()) return;
    final partnerName = _partner == 0
        ? _name.text.trim()
        : '${widget.partners.where((p) => p['id'] == _partner).firstOrNull?['name'] ?? widget.row['partner_name']}';
    final bags = MoneyFormat.parseInput(_bags.text)!;
    setState(() {
      _busy = true;
      _error = null;
    });
    final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
              title: const Text('تأیید اصلاح آرد همکار'),
              content: SingleChildScrollView(
                  child: Text(
                      'همکار: ${widget.row['partner_name']} ← $partnerName\nمقدار: ${widget.row['bags']} ← ${_bags.text} کیسه\nجهت: ${_direction == 'lent' ? 'دادیم به همکار' : 'گرفتیم از همکار'}\nتاریخ: ${_date.text}\nدلیل: ${_reason.text.trim()}\nموجودی و مانده حساب از روی اصلاح تازه محاسبه می‌شوند.')),
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
      await widget.api.correctPartnerFlour(widget.row['id'] as int, {
        'version': widget.row['version'],
        'bags': bags,
        'direction': _direction,
        'date': latinDigits(_date.text.trim()),
        'customer_id': _partner == 0 ? null : _partner,
        'partner_name': _partner == 0 ? _name.text.trim() : null,
        'reason': _reason.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _error = e is ApiException
              ? e.message
              : 'نتیجه اصلاح مشخص نشد؛ ثبت اصلی را دوباره بررسی کنید.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
      canPop: !_busy,
      child: AlertDialog(
        title: const Text('اصلاح ثبت آرد'),
        content: SingleChildScrollView(
            child: Form(
                key: _form,
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  DropdownButtonFormField<int>(
                      initialValue: _partner,
                      isExpanded: true,
                      decoration: const InputDecoration(labelText: 'همکار'),
                      items: [
                        const DropdownMenuItem(
                            value: 0, child: Text('نام دستی (ثبت قدیمی)')),
                        for (final p in widget.partners)
                          DropdownMenuItem(
                              value: p['id'] as int,
                              child: Text('${p['name']}')),
                        if (_partner != 0 &&
                            !widget.partners.any((p) => p['id'] == _partner))
                          DropdownMenuItem(
                              value: _partner,
                              child: Text('${widget.row['partner_name']}')),
                      ],
                      onChanged: _busy
                          ? null
                          : (v) => setState(() => _partner = v ?? 0)),
                  if (_partner == 0)
                    TextFormField(
                        controller: _name,
                        enabled: !_busy,
                        decoration:
                            const InputDecoration(labelText: 'نام همکار'),
                        validator: (v) => (v?.trim().isEmpty ?? true)
                            ? 'نام همکار را بنویسید'
                            : null),
                  TextFormField(
                      controller: _bags,
                      enabled: !_busy,
                      keyboardType:
                          const TextInputType.numberWithOptions(decimal: true),
                      decoration:
                          const InputDecoration(labelText: 'تعداد کیسه'),
                      validator: (v) {
                        final n = MoneyFormat.parseInput(v);
                        if (n == null ||
                            !n.isFinite ||
                            n < 0.01 ||
                            n > 1000000) {
                          return 'تعداد مثبت کیسه وارد کنید';
                        }
                        if (n <
                            ((widget.row['returned_bags'] as num?)
                                    ?.toDouble() ??
                                0)) {
                          return 'مقدار از برگشت ثبت‌شده کمتر نمی‌شود';
                        }
                        return null;
                      }),
                  DropdownButtonFormField<String>(
                      initialValue: _direction,
                      isExpanded: true,
                      decoration:
                          const InputDecoration(labelText: 'جهت جابه‌جایی'),
                      items: const [
                        DropdownMenuItem(
                            value: 'borrowed', child: Text('گرفتیم از همکار')),
                        DropdownMenuItem(
                            value: 'lent', child: Text('دادیم به همکار')),
                      ],
                      onChanged: _busy
                          ? null
                          : (v) =>
                              setState(() => _direction = v ?? _direction)),
                  TextFormField(
                      controller: _date,
                      enabled: !_busy,
                      decoration: const InputDecoration(
                          labelText: 'تاریخ (۱۴۰۵/۰۸/۰۱)'),
                      validator: (v) => (v?.trim().isEmpty ?? true)
                          ? 'تاریخ را وارد کنید'
                          : null),
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
