/// Who the shop buys from, and what a delivery from them looked like.
///
/// The lorry arrives while the owner is out. Until now the docket went in
/// somebody's pocket and reached a desk hours later, if it reached one at
/// all — the same problem the diesel docket had, and the reason this is on
/// the phone and not only in the panel.
library;

/// A name in the picker.
class Supplier {
  const Supplier({
    required this.id,
    required this.name,
    this.kind,
    this.phone,
  });

  factory Supplier.fromJson(Map<String, dynamic> json) => Supplier(
        id: json['id'] as int,
        name: json['name'] as String? ?? '',
        kind: json['kind'] as String?,
        phone: json['phone'] as String?,
      );

  final int id;
  final String name;

  /// The shop's own words for what they are — «کارخانه آرد», «بنکدار».
  final String? kind;
  final String? phone;
}

/// A stocked good, with what one sack of it weighs.
class PurchasableGood {
  const PurchasableGood({
    required this.key,
    required this.name,
    required this.unit,
    required this.bagWeightKg,
  });

  factory PurchasableGood.fromJson(Map<String, dynamic> json) =>
      PurchasableGood(
        key: json['key'] as String? ?? '',
        name: json['name'] as String? ?? '',
        unit: json['unit'] as String? ?? 'کیلوگرم',
        bagWeightKg: _d(json['bag_weight_kg']),
      );

  final String key;
  final String name;
  final String unit;

  /// Zero when the good has no fixed package. The form asks for kilograms
  /// then and offers no sack count — a count converted at an invented
  /// figure is worse than a plain weight.
  final double bagWeightKg;

  bool get isSacked => bagWeightKg > 0;
}

/// An account the money can come out of.
class PayingAccount {
  const PayingAccount({
    required this.id,
    required this.title,
    required this.isDefault,
  });

  factory PayingAccount.fromJson(Map<String, dynamic> json) => PayingAccount(
        id: json['id'] as int,
        title: json['title'] as String? ?? '',
        isDefault: json['is_default'] as bool? ?? false,
      );

  final int id;
  final String title;
  final bool isDefault;
}

/// Everything the delivery form needs, fetched in one call — because it is
/// opened standing at a lorry with one bar of signal.
class PurchaseOptions {
  const PurchaseOptions({
    required this.suppliers,
    required this.goods,
    required this.accounts,
    required this.currencyLabel,
  });

  factory PurchaseOptions.fromJson(Map<String, dynamic> json) =>
      PurchaseOptions(
        suppliers: ((json['suppliers'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(Supplier.fromJson)
            .toList(),
        goods: ((json['items'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(PurchasableGood.fromJson)
            .toList(),
        accounts: ((json['accounts'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(PayingAccount.fromJson)
            .toList(),
        currencyLabel: json['currency_label'] as String? ?? '',
      );

  final List<Supplier> suppliers;
  final List<PurchasableGood> goods;
  final List<PayingAccount> accounts;
  final String currencyLabel;
}

/// One line of an invoice, as the app sends it up.
class PurchaseLineDraft {
  PurchaseLineDraft({
    this.itemKey,
    this.title,
    this.bags,
    this.quantityKg,
    this.unitPrice,
    this.amount,
  });

  /// A stocked good, or null for a line that is money without goods.
  String? itemKey;

  /// What to call a line that has no good — «حمل», «تخلیه».
  String? title;

  double? bags;
  double? quantityKg;
  double? unitPrice;
  double? amount;

  /// A line for a stocked good, in whichever unit that good is counted in.
  ///
  /// The quantity is typed once, and which column it lands in is a fact
  /// about the good rather than a choice the person at the lorry has to
  /// make: a sacked good is counted in sacks and the server derives the
  /// weight, an unsacked one is weighed. Deciding it here rather than in
  /// the form is what lets it be tested without a screen.
  ///
  /// Null when there is nothing to record — a row left untouched.
  static PurchaseLineDraft? forGood(
    PurchasableGood good,
    double quantity,
    double ratePerKg,
  ) {
    if (quantity <= 0 || ratePerKg <= 0) return null;

    return PurchaseLineDraft(
      itemKey: good.key,
      bags: good.isSacked ? quantity : null,
      quantityKg: good.isSacked ? null : quantity,
      unitPrice: ratePerKg,
    );
  }

  /// A line that is money without goods — freight, unloading.
  static PurchaseLineDraft? forCharge(String title, double amount) {
    final named = title.trim();

    if (named.isEmpty || amount <= 0) return null;

    return PurchaseLineDraft(title: named, amount: amount);
  }

  Map<String, dynamic> toJson() => {
        if (itemKey != null) 'item': itemKey,
        if (title != null && title!.isNotEmpty) 'title': title,
        if (bags != null) 'bags': bags,
        if (quantityKg != null) 'quantity_kg': quantityKg,
        if (unitPrice != null) 'unit_price': unitPrice,
        if (amount != null) 'amount': amount,
      };
}

/// A line of an invoice already filed.
class PurchaseLine {
  const PurchaseLine({
    required this.label,
    required this.quantityLabel,
    required this.amountFormatted,
  });

  factory PurchaseLine.fromJson(Map<String, dynamic> json) => PurchaseLine(
        label: json['label'] as String? ?? '',
        quantityLabel: json['quantity_label'] as String? ?? '',
        amountFormatted: json['amount_formatted'] as String? ?? '',
      );

  final String label;
  final String quantityLabel;
  final String amountFormatted;
}

/// A delivery, as the shop reads it back.
class Purchase {
  const Purchase({
    required this.id,
    required this.supplierName,
    required this.purchasedOnDisplay,
    required this.amountFormatted,
    required this.outstandingFormatted,
    required this.isSettled,
    required this.lines,
    this.invoiceNo,
  });

  factory Purchase.fromJson(Map<String, dynamic> json) => Purchase(
        id: json['id'] as int,
        supplierName: json['supplier_name'] as String? ?? '',
        purchasedOnDisplay: json['purchased_on_display'] as String? ?? '',
        amountFormatted: json['amount_formatted'] as String? ?? '',
        outstandingFormatted: json['outstanding_formatted'] as String? ?? '',
        isSettled: json['is_settled'] as bool? ?? false,
        invoiceNo: json['invoice_no'] as String?,
        lines: ((json['items'] as List?) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(PurchaseLine.fromJson)
            .toList(),
      );

  final int id;
  final String supplierName;
  final String purchasedOnDisplay;
  final String amountFormatted;
  final String outstandingFormatted;
  final bool isSettled;
  final String? invoiceNo;
  final List<PurchaseLine> lines;
}

/// What the shop owes one supplier.
class SupplierBalance {
  const SupplierBalance({
    required this.id,
    required this.name,
    required this.balance,
    required this.balanceFormatted,
    required this.invoices,
    required this.unpaidInvoices,
    this.phone,
    this.kind,
  });

  factory SupplierBalance.fromJson(Map<String, dynamic> json) =>
      SupplierBalance(
        id: json['id'] as int,
        name: json['name'] as String? ?? '',
        balance: _d(json['balance']),
        balanceFormatted: json['balance_formatted'] as String? ?? '',
        invoices: (json['invoices'] as num?)?.toInt() ?? 0,
        unpaidInvoices: (json['unpaid_invoices'] as num?)?.toInt() ?? 0,
        phone: json['phone'] as String?,
        kind: json['kind'] as String?,
      );

  final int id;
  final String name;

  /// Positive means the shop owes them; negative means it has paid ahead.
  final double balance;
  final String balanceFormatted;
  final int invoices;
  final int unpaidInvoices;
  final String? phone;
  final String? kind;

  bool get weOwe => balance > 0.01;
}

double _d(Object? value) => switch (value) {
      final num n => n.toDouble(),
      final String s => double.tryParse(s) ?? 0,
      _ => 0,
    };
