/// One line of the balance sheet — a kind of thing owned, or owed.
class BalanceLine {
  const BalanceLine({
    required this.key,
    required this.label,
    required this.amountFormatted,
    this.note,
  });

  final String key;
  final String label;
  final String amountFormatted;

  /// Why the figure is what it is — "به قیمت خرید", say. Not every line
  /// needs one.
  final String? note;

  factory BalanceLine.fromJson(Map<String, dynamic> json) => BalanceLine(
        key: json['key'] as String? ?? '',
        label: json['label'] as String? ?? '',
        amountFormatted: json['amount_formatted'] as String? ?? '',
        note: json['note'] as String?,
      );
}

/// Something the shop owns that the day's work never mentions.
class FixedAssetLine {
  const FixedAssetLine({
    required this.title,
    required this.valueFormatted,
    this.categoryLabel,
    this.purchasedOn,
  });

  final String title;
  final String valueFormatted;
  final String? categoryLabel;
  final String? purchasedOn;

  factory FixedAssetLine.fromJson(Map<String, dynamic> json) => FixedAssetLine(
        title: json['title'] as String? ?? '',
        valueFormatted: json['value_formatted'] as String? ?? '',
        categoryLabel: json['category_label'] as String?,
        purchasedOn: json['purchased_on_display'] as String?,
      );
}

/// A loan and how far through paying it back the shop is.
class LoanLine {
  const LoanLine({
    required this.title,
    required this.remainingFormatted,
    required this.progressPercent,
    this.lender,
    this.nextDueOn,
    this.isOverdue = false,
  });

  final String title;
  final String remainingFormatted;
  final double progressPercent;
  final String? lender;
  final String? nextDueOn;

  /// Past its date and still unpaid — the one state worth chasing today.
  final bool isOverdue;

  factory LoanLine.fromJson(Map<String, dynamic> json) => LoanLine(
        title: json['title'] as String? ?? '',
        remainingFormatted: json['remaining_formatted'] as String? ?? '',
        progressPercent: switch (json['progress_percent']) {
          num n => n.toDouble(),
          String s => double.tryParse(s) ?? 0,
          _ => 0,
        },
        lender: json['lender'] as String?,
        nextDueOn: json['next_due_on_display'] as String?,
        isOverdue: json['is_overdue'] == true,
      );
}

/// What the shop owns against what it owes.
class BalanceSheet {
  const BalanceSheet({
    required this.assets,
    required this.liabilities,
    required this.assetTotalFormatted,
    required this.liabilityTotalFormatted,
    required this.equityFormatted,
    required this.isSolvent,
    this.asOf,
    this.fixedAssets = const [],
    this.loans = const [],
  });

  final List<BalanceLine> assets;
  final List<BalanceLine> liabilities;

  final String assetTotalFormatted;
  final String liabilityTotalFormatted;

  /// What would be left for the owners if everything were settled today.
  final String equityFormatted;

  /// False when the shop owes more than it holds — worth saying plainly
  /// rather than leaving to be read off a minus sign.
  final bool isSolvent;

  final String? asOf;

  final List<FixedAssetLine> fixedAssets;
  final List<LoanLine> loans;

  static List<T> _list<T>(dynamic raw, T Function(Map<String, dynamic>) read) =>
      ((raw as List?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(read)
          .toList();

  factory BalanceSheet.fromJson(Map<String, dynamic> json) => BalanceSheet(
        assets: _list(json['assets'], BalanceLine.fromJson),
        liabilities: _list(json['liabilities'], BalanceLine.fromJson),
        assetTotalFormatted: json['asset_total_formatted'] as String? ?? '',
        liabilityTotalFormatted: json['liability_total_formatted'] as String? ?? '',
        equityFormatted: json['equity_formatted'] as String? ?? '',
        isSolvent: json['is_solvent'] != false,
        asOf: json['as_of'] as String?,
        fixedAssets: _list(json['fixed_assets'], FixedAssetLine.fromJson),
        loans: _list(json['loans'], LoanLine.fromJson),
      );

  /// A shop that has recorded nothing at all — worth a sentence rather than
  /// an empty sheet.
  bool get isEmpty => assets.isEmpty && liabilities.isEmpty;
}
