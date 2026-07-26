import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/customer.dart';
import '../../models/entries.dart';
import '../../models/flour_sale.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';

/// Sells flour out of the warehouse, by the kilo or by the sack.
///
/// The weight and the total are always recomputed by the server; what is
/// shown here is a preview so the seller can check it before committing.
class FlourSaleSheet extends StatefulWidget {
  const FlourSaleSheet({super.key, required this.api, this.bakery});

  final BakeryApi api;
  final Bakery? bakery;

  @override
  State<FlourSaleSheet> createState() => _FlourSaleSheetState();
}

class _FlourSaleSheetState extends State<FlourSaleSheet> {
  final _quantityController = TextEditingController();
  final _priceController = TextEditingController();
  final _noteController = TextEditingController();

  FlourUnit _unit = FlourUnit.kg;
  PaymentType _paymentType = PaymentType.cash;
  Customer? _customer;

  FlourSaleOptions? _options;
  List<Customer> _customers = const [];

  bool _loading = true;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadOptions();
  }

  @override
  void dispose() {
    _quantityController.dispose();
    _priceController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  Currency get _currency => widget.bakery?.currency ?? Currency.toman;

  Future<void> _loadOptions() async {
    try {
      final options = await widget.api.flourSaleOptions();

      // Customers are a convenience for credit sales; failing to load them
      // must not block a cash sale.
      List<Customer> customers = const [];
      try {
        customers = await widget.api.customers();
      } on ApiException {
        customers = const [];
      }

      if (!mounted) return;

      setState(() {
        _options = options;
        _customers = customers;
        _loading = false;
        _applyDefaultPrice();
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    }
  }

  void _applyDefaultPrice() {
    final price = _options?.priceFor(_unit) ?? 0;
    _priceController.text = price > 0 ? _trim(price) : '';
  }

  void _changeUnit(FlourUnit unit) {
    setState(() {
      _unit = unit;
      // The rate means something different per unit, so re-suggest it
      // rather than leaving a kilo price against a sack.
      _applyDefaultPrice();
    });
  }

  static String _trim(double value) =>
      value == value.roundToDouble() ? value.toStringAsFixed(0) : '$value';

  double get _quantity =>
      double.tryParse(_quantityController.text.trim()) ?? 0;

  double get _unitPrice => double.tryParse(_priceController.text.trim()) ?? 0;

  double get _weightKg => _unit == FlourUnit.bag
      ? _quantity * (_options?.bagWeightKg ?? 0)
      : _quantity;

  double get _total => _quantity * _unitPrice;

  bool get _needsCustomer =>
      _paymentType == PaymentType.credit || _paymentType == PaymentType.schools;

  /// Whether the warehouse actually holds what is being sold.
  bool get _exceedsStock {
    final available = _options?.availableKg ?? 0;
    return _weightKg > available;
  }

  Future<void> _submit() async {
    if (_quantity <= 0) {
      setState(() => _error = 'مقدار فروش را وارد کنید.');
      return;
    }

    if (_exceedsStock) {
      setState(() => _error = 'موجودی انبار کافی نیست.');
      return;
    }

    if (_needsCustomer && _customer == null) {
      setState(() => _error = 'برای فروش نسیه، مشتری را انتخاب کنید.');
      return;
    }

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      await widget.api.recordFlourSale(
        unit: _unit,
        quantity: _quantity,
        paymentType: _paymentType,
        unitPrice: _unitPrice > 0 ? _unitPrice : null,
        customerId: _customer?.id,
        note: _noteController.text.trim(),
      );

      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _saving = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final inset = MediaQuery.of(context).viewInsets.bottom;

    return Padding(
      padding: EdgeInsets.fromLTRB(20, 18, 20, inset + 20),
      child: _loading
          ? const SizedBox(
              height: 180,
              child: Center(child: CircularProgressIndicator()),
            )
          : SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Icon(Icons.inventory_2_rounded, color: scheme.primary),
                      const SizedBox(width: 10),
                      Text(
                        'فروش آرد',
                        style: Theme.of(context)
                            .textTheme
                            .titleMedium
                            ?.copyWith(fontWeight: FontWeight.w800),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  if (_options != null)
                    Text(
                      'موجودی انبار: '
                      '${_options!.availableKg.toStringAsFixed(1)} کیلوگرم'
                      '  •  ${_options!.availableBags.toStringAsFixed(1)} کیسه',
                      style: Theme.of(context).textTheme.bodySmall?.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                    ),

                  const SizedBox(height: 16),
                  SegmentedButton<FlourUnit>(
                    segments: const [
                      ButtonSegment(
                        value: FlourUnit.kg,
                        label: Text('کیلویی'),
                        icon: Icon(Icons.scale_rounded),
                      ),
                      ButtonSegment(
                        value: FlourUnit.bag,
                        label: Text('کیسه‌ای'),
                        icon: Icon(Icons.shopping_bag_rounded),
                      ),
                    ],
                    selected: {_unit},
                    onSelectionChanged: (s) => _changeUnit(s.first),
                  ),

                  const SizedBox(height: 14),
                  TextField(
                    controller: _quantityController,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      labelText: 'مقدار (${_unit.label})',
                      prefixIcon: const Icon(Icons.numbers_rounded),
                      helperText: _unit == FlourUnit.bag && _options != null
                          ? 'هر کیسه ${_trim(_options!.bagWeightKg)} کیلوگرم'
                          : null,
                    ),
                  ),

                  const SizedBox(height: 12),
                  TextField(
                    controller: _priceController,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      labelText: 'قیمت هر ${_unit.label} (${_currency.label})',
                      prefixIcon: const Icon(Icons.sell_rounded),
                    ),
                  ),

                  const SizedBox(height: 14),
                  _Preview(
                    weightKg: _weightKg,
                    total: _total,
                    currency: _currency,
                    overStock: _exceedsStock,
                  ),

                  const SizedBox(height: 14),
                  DropdownButtonFormField<PaymentType>(
                    initialValue: _paymentType,
                    decoration: const InputDecoration(
                      labelText: 'نوع پرداخت',
                      prefixIcon: Icon(Icons.payments_rounded),
                    ),
                    items: [
                      for (final type in PaymentType.values)
                        DropdownMenuItem(value: type, child: Text(type.label)),
                    ],
                    onChanged: (value) => setState(
                      () => _paymentType = value ?? PaymentType.cash,
                    ),
                  ),

                  if (_needsCustomer) ...[
                    const SizedBox(height: 12),
                    DropdownButtonFormField<Customer>(
                      initialValue: _customer,
                      decoration: const InputDecoration(
                        labelText: 'مشتری',
                        prefixIcon: Icon(Icons.person_rounded),
                      ),
                      items: [
                        for (final customer in _customers)
                          DropdownMenuItem(
                            value: customer,
                            child: Text(customer.name),
                          ),
                      ],
                      onChanged: (value) => setState(() => _customer = value),
                    ),
                  ],

                  const SizedBox(height: 12),
                  TextField(
                    controller: _noteController,
                    decoration: const InputDecoration(
                      labelText: 'توضیحات (اختیاری)',
                      prefixIcon: Icon(Icons.notes_rounded),
                    ),
                  ),

                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      _error!,
                      style: TextStyle(color: scheme.error),
                    ),
                  ],

                  const SizedBox(height: 18),
                  FilledButton.icon(
                    onPressed: _saving ? null : _submit,
                    icon: _saving
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.check_rounded),
                    label: Text(_saving ? 'در حال ثبت…' : 'ثبت فروش آرد'),
                  ),
                ],
              ),
            ),
    );
  }
}

/// Mirrors the server's calculation so the seller sees the weight and total
/// before committing to the sale.
class _Preview extends StatelessWidget {
  const _Preview({
    required this.weightKg,
    required this.total,
    required this.currency,
    required this.overStock,
  });

  final double weightKg;
  final double total;
  final Currency currency;
  final bool overStock;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final colour = overStock ? scheme.error : scheme.primary;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: colour.withValues(alpha: 0.09),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: colour.withValues(alpha: 0.3)),
      ),
      child: Row(
        children: [
          Icon(
            overStock
                ? Icons.warning_amber_rounded
                : Icons.calculate_rounded,
            color: colour,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${weightKg.toStringAsFixed(2)} کیلوگرم',
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(fontWeight: FontWeight.w700, color: colour),
                ),
                Text(
                  overStock
                      ? 'بیش از موجودی انبار'
                      // The price came from the API already converted to the
                      // display unit, so it must not be converted twice.
                      : '${MoneyFormat.plain(total)} ${currency.label}',
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
