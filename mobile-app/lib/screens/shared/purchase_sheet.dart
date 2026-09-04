import 'package:flutter/material.dart';

import '../../models/purchase.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../widgets/common.dart';

/// Writing down a delivery while the lorry is still at the door.
///
/// The docket used to have to reach a desk before anything was recorded,
/// and by then it was in somebody's pocket — the same problem the diesel
/// docket had, and it was solved the same way. Whoever is holding a phone
/// enters it here; what the shop owes for it is settled later, by whoever
/// holds the money.
///
/// Only two things are asked for: who brought it and what came off. The
/// payment is optional and defaults to nothing handed over, because that
/// is how this shop buys flour — on the mill's book.
class PurchaseSheet extends StatefulWidget {
  const PurchaseSheet({super.key, required this.api});

  final BakeryApi api;

  /// Opens the sheet and answers true when a delivery was recorded, so the
  /// page underneath knows to re-read its stock.
  static Future<bool> open(BuildContext context, BakeryApi api) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => PurchaseSheet(api: api),
    );

    return saved ?? false;
  }

  @override
  State<PurchaseSheet> createState() => _PurchaseSheetState();
}

class _PurchaseSheetState extends State<PurchaseSheet> {
  PurchaseOptions? _options;
  bool _loading = true;
  bool _saving = false;
  String? _error;

  Supplier? _supplier;
  final _newSupplier = TextEditingController();
  final _invoiceNo = TextEditingController();
  final _paid = TextEditingController();

  final List<_LineFields> _lines = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _newSupplier.dispose();
    _invoiceNo.dispose();
    _paid.dispose();

    for (final line in _lines) {
      line.dispose();
    }

    super.dispose();
  }

  Future<void> _load() async {
    try {
      final options = await widget.api.purchaseOptions();

      if (!mounted) return;

      setState(() {
        _options = options;
        _loading = false;
        // Flour is what this shop buys, so the first line starts on it
        // rather than on an empty picker.
        _lines.add(_LineFields(
          good: options.goods.where((g) => g.key == 'flour').firstOrNull ??
              options.goods.firstOrNull,
        ));
      });
    } catch (e) {
      if (!mounted) return;

      setState(() {
        _loading = false;
        _error = e is ApiException ? e.message : 'اطلاعات فرم خوانده نشد.';
      });
    }
  }

  /// What the shop is about to be invoiced, so the figure is on screen
  /// before it is agreed to rather than after.
  double get _total => _lines.fold(0, (sum, line) => sum + line.amount);

  Future<void> _save() async {
    final drafts = <PurchaseLineDraft>[];

    for (final line in _lines) {
      final draft = line.toDraft();

      if (draft != null) drafts.add(draft);
    }

    if (drafts.isEmpty) {
      showMessage(context, 'دست‌کم یک ردیف با مقدار و مبلغ لازم است.',
          isError: true);

      return;
    }

    final named = _supplier == null && _newSupplier.text.trim().isEmpty;

    if (named) {
      showMessage(context, 'تأمین‌کننده را انتخاب یا نامش را وارد کنید.',
          isError: true);

      return;
    }

    setState(() => _saving = true);

    try {
      final outcome = await widget.api.recordPurchase(
        lines: drafts,
        supplierId: _supplier?.id,
        supplierName: _newSupplier.text.trim(),
        invoiceNo: _invoiceNo.text.trim(),
        paidAmount: double.tryParse(_paid.text.trim()) ?? 0,
      );

      if (!mounted) return;

      // Said plainly when it is waiting. «ثبت شد» over a queued write is
      // how somebody goes looking for the invoice in the panel an hour
      // later, does not find it, and types it a second time.
      showMessage(
        context,
        outcome.queued
            ? 'بدون اینترنت ذخیره شد — با وصل‌شدن ارسال می‌شود.'
            : 'فاکتور خرید ثبت شد.',
      );
      Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (!mounted) return;

      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final options = _options;

    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 22),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  Icon(Icons.local_shipping_rounded,
                      color: AppColors.stock, size: IconSize.large),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      'ثبت محموله',
                      style: theme.textTheme.titleMedium
                          ?.copyWith(fontWeight: FontWeight.w800),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                'همان‌جا که ماشین ایستاده. پرداخت را می‌شود بعداً ثبت کرد.',
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: 16),

              if (_loading)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 40),
                  child: Center(child: CircularProgressIndicator()),
                )
              else if (_error != null || options == null)
                ErrorBox(message: _error ?? 'اطلاعات فرم خوانده نشد.', onRetry: _load)
              else ...[
                _supplierField(options),
                const SizedBox(height: 12),

                TextField(
                  controller: _invoiceNo,
                  decoration: const InputDecoration(
                    labelText: 'شماره فاکتور (اختیاری)',
                    prefixIcon: Icon(Icons.tag_rounded),
                  ),
                ),
                const SizedBox(height: 18),

                for (var i = 0; i < _lines.length; i++) ...[
                  _lineCard(options, i),
                  const SizedBox(height: 10),
                ],

                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton.icon(
                    onPressed: _saving
                        ? null
                        : () => setState(() => _lines.add(_LineFields())),
                    icon: const Icon(Icons.add_rounded, size: IconSize.row),
                    label: const Text('افزودن ردیف'),
                  ),
                ),
                const SizedBox(height: 8),

                _totalRow(theme, options),
                const SizedBox(height: 12),

                TextField(
                  controller: _paid,
                  keyboardType: TextInputType.number,
                  onChanged: (_) => setState(() {}),
                  decoration: InputDecoration(
                    labelText: 'پرداخت‌شده هنگام تحویل',
                    helperText: 'خالی یعنی چیزی پرداخت نشده و روی حساب می‌ماند',
                    prefixIcon: const Icon(Icons.payments_rounded),
                    suffixText: options.currencyLabel,
                  ),
                ),
                const SizedBox(height: 20),

                FilledButton(
                  onPressed: _saving ? null : _save,
                  child: _saving
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Text('ثبت فاکتور'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  /// The mill, chosen from the list or named on the spot.
  ///
  /// A name typed here opens an account under it. Sending somebody to a
  /// different screen to add the supplier first is how a delivery ends up
  /// unrecorded.
  Widget _supplierField(PurchaseOptions options) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DropdownButtonFormField<Supplier?>(
          initialValue: _supplier,
          isExpanded: true,
          decoration: const InputDecoration(
            labelText: 'تأمین‌کننده',
            prefixIcon: Icon(Icons.storefront_rounded),
          ),
          items: [
            for (final supplier in options.suppliers)
              DropdownMenuItem(
                value: supplier,
                child: Text(supplier.kind == null || supplier.kind!.isEmpty
                    ? supplier.name
                    : '${supplier.name}  •  ${supplier.kind}'),
              ),
            const DropdownMenuItem(value: null, child: Text('— نام جدید —')),
          ],
          onChanged: _saving ? null : (v) => setState(() => _supplier = v),
        ),
        if (_supplier == null) ...[
          const SizedBox(height: 10),
          TextField(
            controller: _newSupplier,
            decoration: const InputDecoration(
              labelText: 'نام تأمین‌کننده',
              hintText: 'کارخانه آرد زاهدان',
              prefixIcon: Icon(Icons.edit_rounded),
            ),
          ),
        ],
      ],
    );
  }

  Widget _lineCard(PurchaseOptions options, int index) {
    final line = _lines[index];
    final good = line.good;

    return Card(
      // Keyed to the line, not its position: removing a row otherwise
      // hands its dropdown state to whichever row moves up into the slot.
      key: ValueKey(line),
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<PurchasableGood?>(
                    initialValue: good,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'کالا',
                      isDense: true,
                    ),
                    items: [
                      for (final item in options.goods)
                        DropdownMenuItem(value: item, child: Text(item.name)),
                      const DropdownMenuItem(
                        value: null,
                        child: Text('بدون کالا — فقط مبلغ'),
                      ),
                    ],
                    onChanged: _saving
                        ? null
                        : (v) => setState(() => line.good = v),
                  ),
                ),
                if (_lines.length > 1)
                  IconButton(
                    onPressed: _saving
                        ? null
                        : () => setState(() => _lines.removeAt(index).dispose()),
                    tooltip: 'حذف ردیف',
                    icon: const Icon(Icons.close_rounded),
                  ),
              ],
            ),

            if (good == null) ...[
              const SizedBox(height: 8),
              TextField(
                controller: line.title,
                decoration: const InputDecoration(
                  labelText: 'عنوان',
                  hintText: 'حمل، تخلیه',
                  isDense: true,
                ),
              ),
            ],

            const SizedBox(height: 8),
            Row(
              children: [
                if (good != null)
                  Expanded(
                    child: TextField(
                      controller: line.quantity,
                      keyboardType: const TextInputType.numberWithOptions(
                        decimal: true,
                      ),
                      onChanged: (_) => setState(() {}),
                      decoration: InputDecoration(
                        // Sacks when the good has a size, kilograms when
                        // it has none — a sack count converted at an
                        // invented figure is worse than a plain weight.
                        labelText: good.isSacked ? 'کیسه' : 'کیلوگرم',
                        isDense: true,
                      ),
                    ),
                  ),
                if (good != null) const SizedBox(width: 10),
                Expanded(
                  child: TextField(
                    controller: good == null ? line.amountField : line.rate,
                    keyboardType: TextInputType.number,
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      labelText: good == null ? 'مبلغ' : 'نرخ هر کیلو',
                      isDense: true,
                      suffixText: options.currencyLabel,
                    ),
                  ),
                ),
              ],
            ),

            if (good != null && line.amount > 0) ...[
              const SizedBox(height: 6),
              Text(
                line.explain(good),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _totalRow(ThemeData theme, PurchaseOptions options) {
    final paid = double.tryParse(_paid.text.trim()) ?? 0;
    final owed = _total - paid;

    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: Text('جمع فاکتور', style: theme.textTheme.bodyMedium),
            ),
            Text(
              '${_group(_total)} ${options.currencyLabel}',
              style: theme.textTheme.titleMedium
                  ?.copyWith(fontWeight: FontWeight.w900),
            ),
          ],
        ),
        if (owed > 0.01) ...[
          const SizedBox(height: 4),
          Row(
            children: [
              Expanded(
                child: Text('روی حساب می‌ماند',
                    style: theme.textTheme.bodySmall),
              ),
              Text(
                '${_group(owed)} ${options.currencyLabel}',
                style: theme.textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: AppColors.attention,
                ),
              ),
            ],
          ),
        ],
      ],
    );
  }

  /// The shop writes money grouped with the Persian comma, the same way
  /// every figure the server formats is written.
  String _group(double value) {
    final digits = value.round().toString();
    final out = StringBuffer();

    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) out.write('،');
      out.write(digits[i]);
    }

    return out.toString();
  }
}

/// The controllers behind one line, and the arithmetic the shop expects to
/// see before it agrees to a figure.
class _LineFields {
  _LineFields({this.good});

  PurchasableGood? good;

  final title = TextEditingController();
  final quantity = TextEditingController();
  final rate = TextEditingController();
  final amountField = TextEditingController();

  double get _quantityTyped => double.tryParse(quantity.text.trim()) ?? 0;

  double get _rateTyped => double.tryParse(rate.text.trim()) ?? 0;

  /// Kilograms, whichever unit was typed.
  double kilograms(PurchasableGood good) =>
      good.isSacked ? _quantityTyped * good.bagWeightKg : _quantityTyped;

  double get amount {
    final item = good;

    if (item == null) return double.tryParse(amountField.text.trim()) ?? 0;

    return kilograms(item) * _rateTyped;
  }

  String explain(PurchasableGood good) {
    final kg = kilograms(good);

    return good.isSacked
        ? '${_trim(_quantityTyped)} کیسه  •  ${_trim(kg)} کیلوگرم'
        : '${_trim(kg)} کیلوگرم';
  }

  /// Null when the line is blank, so an untouched extra row is dropped
  /// rather than refused — the server would reject it, and being told off
  /// for a row you did not fill in is not a useful answer.
  PurchaseLineDraft? toDraft() {
    final item = good;

    if (item == null) {
      return PurchaseLineDraft.forCharge(
        title.text,
        double.tryParse(amountField.text.trim()) ?? 0,
      );
    }

    return PurchaseLineDraft.forGood(item, _quantityTyped, _rateTyped);
  }

  void dispose() {
    title.dispose();
    quantity.dispose();
    rate.dispose();
    amountField.dispose();
  }
}

String _trim(double value) => value == value.roundToDouble()
    ? value.toStringAsFixed(0)
    : value.toStringAsFixed(2);
