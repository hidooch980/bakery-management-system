import 'package:flutter/material.dart';

import '../../models/bakery.dart';
import '../../models/customer.dart';
import '../../models/ledger_entry.dart';
import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../theme/app_theme.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import '../shared/consignment_flour_screen.dart';

/// What the admin can record from the phone, without opening the panel.
enum AdminRecordKind {
  expense('هزینه', Icons.trending_down_rounded, AppColors.moneyOut),
  income('درآمد', Icons.trending_up_rounded, AppColors.moneyIn),
  intake('ورودی', Icons.local_shipping_rounded, AppColors.stock),
  consignment('آرد همکار', Icons.swap_horiz_rounded, AppColors.partner);

  const AdminRecordKind(this.label, this.icon, this.color);

  final String label;
  final IconData icon;
  final Color color;
}

/// What the warehouse can take in, and the unit each arrives in.
///
/// Flour comes in sacks and is counted that way; salt and yeast are
/// weighed. The server enforces this — a sack count for salt is refused
/// rather than converted at a weight nobody measures — so the form asks
/// for whichever the chosen item actually uses.
enum StockItem {
  flour('flour', 'آرد', true, 'کیسه'),
  salt('salt', 'نمک', false, 'کیلوگرم'),
  yeastDry('yeast_dry', 'خمیرمایه خشک', false, 'کیلوگرم'),
  yeastWet('yeast_wet', 'خمیرمایه تر', false, 'کیلوگرم');

  const StockItem(this.apiValue, this.label, this.inSacks, this.unit);

  final String apiValue;
  final String label;
  final bool inSacks;
  final String unit;
}

/// A single sheet that records money in, money out, flour bought, or flour
/// swapped with another bakery — the four things an admin does away from a
/// desk, so they should not require the web panel.
class AdminRecordSheet extends StatefulWidget {
  const AdminRecordSheet({
    super.key,
    required this.api,
    required this.kind,
    this.bakery,
  });

  final BakeryApi api;
  final AdminRecordKind kind;
  final Bakery? bakery;

  @override
  State<AdminRecordSheet> createState() => _AdminRecordSheetState();
}

class _AdminRecordSheetState extends State<AdminRecordSheet> {
  final _formKey = GlobalKey<FormState>();
  final _title = TextEditingController();

  /// Partner bakeries already on file. Empty until they load, and empty
  /// for good if the shop has never defined one — in which case the form
  /// falls back to the free-text field it has always been.
  List<Customer> _partners = const [];
  Customer? _partner;
  bool _partnersLoading = false;
  bool _newPartner = false;
  final _amount = TextEditingController();
  final _note = TextEditingController();

  List<LedgerCategory> _categories = const [];
  String? _category;

  /// Which way the flour went, for a consignment entry.
  String _direction = 'lent';

  /// What is being brought in. Flour first: it is most of what arrives.
  StockItem _item = StockItem.flour;

  bool _saving = false;

  bool get _isMoney =>
      widget.kind == AdminRecordKind.expense ||
      widget.kind == AdminRecordKind.income;

  Currency get _unit => widget.bakery?.currency ?? Currency.toman;

  @override
  void initState() {
    super.initState();

    if (widget.kind == AdminRecordKind.consignment) {
      _loadPartners();
    }
    if (_isMoney) _loadCategories();
  }

  Future<void> _loadCategories() async {
    try {
      final list = widget.kind == AdminRecordKind.expense
          ? await widget.api.expenseCategories()
          : await widget.api.incomeCategories();

      if (mounted) {
        setState(() {
          _categories = list;
          _category = list.isEmpty ? null : list.first.key;
        });
      }
    } on ApiException {
      // Without categories the form blocks rather than guessing one.
    }
  }

  Future<void> _loadPartners() async {
    setState(() => _partnersLoading = true);

    try {
      final partners = await widget.api.customers(partnersOnly: true);
      if (!mounted) return;
      setState(() {
        _partners = partners;
        // One partner and nothing to choose between: pick it. Two taps
        // saved on the commonest case.
        if (partners.length == 1) _partner = partners.first;
        _partnersLoading = false;
      });
    } on ApiException {
      // Not fatal: the name can still be typed. Better a form that works
      // without the list than one that refuses to open without it.
      if (!mounted) return;
      setState(() {
        _partnersLoading = false;
        _newPartner = true;
      });
    }
  }

  @override
  void dispose() {
    _title.dispose();
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    if (_isMoney && _category == null) {
      showMessage(context, 'دسته‌بندی را انتخاب کنید.', isError: true);
      return;
    }

    setState(() => _saving = true);

    try {
      // The field carries grouping separators, so it cannot go through
      // double.parse directly. The validator has already run, so a null here
      // is not reachable.
      final value = MoneyFormat.parseInput(_amount.text.trim()) ?? 0;
      final note = _note.text.trim();

      final queued = switch (widget.kind) {
        AdminRecordKind.expense => await widget.api.recordExpense(
            category: _category!,
            title: _title.text.trim(),
            amount: value,
            note: note,
          ),
        AdminRecordKind.income => await widget.api.recordIncome(
            category: _category!,
            title: _title.text.trim(),
            amount: value,
            note: note,
          ),
        AdminRecordKind.intake => await widget.api.recordStockIntake(
            item: _item.apiValue,
            quantity: value,
            inSacks: _item.inSacks,
            note: note.isEmpty ? _title.text.trim() : note,
          ),
        AdminRecordKind.consignment => await widget.api.recordConsignmentFlour(
            partnerName: _partner?.name ?? _title.text.trim(),
            customerId: _partner?.id,
            direction: _direction,
            bags: value,
            note: note,
          ),
      };

      if (!mounted) return;
      Navigator.pop(context, true);
      showMessage(
        context,
        queued
            ? 'اینترنت وصل نیست؛ ثبت ذخیره شد و با اتصال بعدی ارسال می‌شود.'
            : '${widget.kind.label} ثبت شد.',
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  String get _amountLabel => switch (widget.kind) {
        AdminRecordKind.intake =>
          _item.inSacks ? 'تعداد کیسه' : 'مقدار به کیلوگرم',
        AdminRecordKind.consignment => 'تعداد کیسه',
        _ => 'مبلغ',
      };

  String get _amountSuffix => switch (widget.kind) {
        AdminRecordKind.intake => _item.unit,
        AdminRecordKind.consignment => 'کیسه',
        _ => _unit.label,
      };

  String get _titleLabel => switch (widget.kind) {
        AdminRecordKind.consignment => 'نام نانوایی همکار',
        AdminRecordKind.intake => 'توضیح (مثلاً نام تأمین‌کننده)',
        _ => 'عنوان',
      };

  bool get _titleRequired => widget.kind != AdminRecordKind.intake;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 44,
                    height: 4,
                    decoration: BoxDecoration(
                      color: scheme.outlineVariant,
                      borderRadius: BorderRadius.circular(Corner.hair),
                    ),
                  ),
                ),
                const SizedBox(height: 22),
                Row(
                  children: [
                    Icon(widget.kind.icon, color: widget.kind.color),
                    const SizedBox(width: 10),
                    Text(
                      'ثبت ${widget.kind.label}',
                      style: Theme.of(context)
                          .textTheme
                          .titleLarge
                          ?.copyWith(fontWeight: FontWeight.w800),
                    ),
                  ],
                ),
                const SizedBox(height: 20),

                if (_isMoney) ...[
                  DropdownButtonFormField<String>(
                    initialValue: _category,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'دسته‌بندی',
                      prefixIcon: Icon(Icons.category_rounded),
                    ),
                    items: [
                      for (final category in _categories)
                        DropdownMenuItem(
                          value: category.key,
                          child: Text(category.label),
                        ),
                    ],
                    onChanged: (value) => setState(() => _category = value),
                  ),
                  const SizedBox(height: 16),
                ],

                if (widget.kind == AdminRecordKind.intake) ...[
                  DropdownButtonFormField<StockItem>(
                    initialValue: _item,
                    isExpanded: true,
                    decoration: const InputDecoration(
                      labelText: 'چه چیزی وارد شد',
                      prefixIcon: Icon(Icons.inventory_2_rounded),
                    ),
                    items: [
                      for (final item in StockItem.values)
                        DropdownMenuItem(
                          value: item,
                          child: Text('${item.label} — ${item.unit}'),
                        ),
                    ],
                    // The amount field's label and suffix follow the item,
                    // and a count typed for one unit means something else
                    // in the other, so it is cleared rather than carried.
                    onChanged: (value) => setState(() {
                      if (value == null || value == _item) return;

                      _item = value;
                      _amount.clear();
                    }),
                  ),
                  const SizedBox(height: 16),
                ],

                if (widget.kind == AdminRecordKind.consignment) ...[
                  Card(
                    margin: EdgeInsets.zero,
                    child: RadioGroup<String>(
                      groupValue: _direction,
                      onChanged: (value) =>
                          setState(() => _direction = value ?? 'lent'),
                      child: const Column(
                        children: [
                          RadioListTile<String>(
                            value: 'lent',
                            title: Text('دادیم به همکار'),
                            dense: true,
                          ),
                          Divider(height: 1),
                          RadioListTile<String>(
                            value: 'borrowed',
                            title: Text('گرفتیم از همکار'),
                            dense: true,
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                ],

                // On the consignment form the partner is chosen from the
                // bakeries already on file rather than typed from memory.
                // Typing it every time scattered one partner's history
                // across «عبدالرئوف» and «عبد الرئوف» with no way to see
                // that they were the same shop.
                if (widget.kind == AdminRecordKind.consignment &&
                    !_newPartner &&
                    (_partners.isNotEmpty || _partnersLoading)) ...[
                  DropdownButtonFormField<Customer>(
                    // `value` was deprecated after Flutter 3.33 in favour
                    // of `initialValue`, which — unlike `value` — is read
                    // once and then owned by the field's own state. The
                    // single partner is selected after the list loads,
                    // which is after the first build, so without a key
                    // that would leave the box looking empty while a
                    // partner was in fact chosen. The key re-creates the
                    // field when the selection changes underneath it.
                    key: ValueKey(_partner?.id),
                    initialValue: _partner,
                    isExpanded: true,
                    decoration: InputDecoration(
                      labelText: _titleLabel,
                      prefixIcon: const Icon(Icons.storefront_outlined),
                      helperText: _partnersLoading ? 'در حال بارگذاری…' : null,
                    ),
                    items: [
                      for (final p in _partners)
                        DropdownMenuItem(value: p, child: Text(p.name)),
                    ],
                    onChanged: (value) => setState(() => _partner = value),
                    validator: (value) =>
                        value == null ? 'نانوایی همکار را انتخاب کنید' : null,
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      TextButton.icon(
                        onPressed: () => setState(() {
                          _newPartner = true;
                          _partner = null;
                        }),
                        icon: const Icon(Icons.add_rounded, size: 18),
                        label: const Text('همکار جدید'),
                      ),
                    ],
                  ),
                ] else
                  TextFormField(
                    controller: _title,
                    decoration: InputDecoration(
                      labelText: _titleLabel,
                      prefixIcon: const Icon(Icons.label_outline_rounded),
                    ),
                    validator: (value) {
                      if (!_titleRequired) return null;
                      if (value == null || value.trim().isEmpty) {
                        return 'این مورد را وارد کنید';
                      }
                      return null;
                    },
                  ),

                // Straight to what is already out. Deciding whether to
                // lend twenty more sacks means knowing about the
                // fifty-six that have not come back, and that list lived
                // only in the panel — the app could write a consignment
                // and had no way to read one back.
                if (widget.kind == AdminRecordKind.consignment)
                  Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: TextButton.icon(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) =>
                              ConsignmentFlourScreen(api: widget.api),
                        ),
                      ),
                      icon: const Icon(Icons.list_alt_rounded, size: 18),
                      label: const Text('آرد امانی‌های باز'),
                    ),
                  ),

                const SizedBox(height: 16),

                TextFormField(
                  controller: _amount,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: const [GroupedAmountInputFormatter()],
                  decoration: InputDecoration(
                    labelText: _amountLabel,
                    prefixIcon: const Icon(Icons.tag_rounded),
                    suffixText: _amountSuffix,
                  ),
                  validator: (value) {
                    final parsed = MoneyFormat.parseInput(value?.trim());
                    if (parsed == null) return 'یک عدد معتبر وارد کنید';
                    if (parsed <= 0) return 'مقدار باید بیشتر از صفر باشد';
                    return null;
                  },
                ),
                const SizedBox(height: 16),

                TextFormField(
                  controller: _note,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'توضیحات (اختیاری)',
                    prefixIcon: Icon(Icons.notes_rounded),
                  ),
                ),

                const SizedBox(height: 24),
                FilledButton.icon(
                  onPressed: _saving ? null : _save,
                  icon: _saving
                      ? SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Theme.of(context).colorScheme.onPrimary),
                        )
                      : const Icon(Icons.check_rounded),
                  label: Text(_saving ? 'در حال ثبت…' : 'ثبت'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
