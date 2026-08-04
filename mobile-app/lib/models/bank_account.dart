/// A bank account the shop banks its card takings into.
///
/// Balances are derived on the server from the account's own ledger, so the
/// app only ever reads them — there is no figure here to keep in step.
class BankAccount {
  const BankAccount({
    required this.id,
    required this.title,
    required this.balance,
    required this.balanceFormatted,
    this.label,
    this.bankName,
    this.isDefault = false,
    this.isActive = true,
    this.isOverdrawn = false,
  });

  final int id;
  final String title;

  /// The shop's own name for it, plus the bank, when both are recorded.
  final String? label;

  final String? bankName;

  final double balance;

  /// Already carries the currency the shop displays in, so the app does not
  /// have to know whether it is toman or rial.
  final String balanceFormatted;

  final bool isDefault;
  final bool isActive;
  final bool isOverdrawn;

  static double _toDouble(dynamic value) => switch (value) {
        num n => n.toDouble(),
        String s => double.tryParse(s) ?? 0,
        _ => 0,
      };

  static bool _toBool(dynamic value) => value == true || value == 1 || value == '1';

  factory BankAccount.fromJson(Map<String, dynamic> json) => BankAccount(
        id: (json['id'] as num?)?.toInt() ?? 0,
        title: json['title'] as String? ?? '',
        label: json['label'] as String?,
        bankName: json['bank_name'] as String?,
        balance: _toDouble(json['balance']),
        balanceFormatted: json['balance_formatted'] as String? ?? '',
        isDefault: _toBool(json['is_default']),
        isActive: _toBool(json['is_active']),
        isOverdrawn: _toBool(json['is_overdrawn']),
      );
}

/// Every account, with what they come to together.
class BankBalances {
  const BankBalances({
    required this.accounts,
    required this.totalFormatted,
    required this.total,
  });

  final List<BankAccount> accounts;
  final double total;
  final String totalFormatted;

  /// The total counts only accounts still in use, the way the server sums
  /// it — a closed account's balance is not money the shop can spend.
  factory BankBalances.fromJson(Map<String, dynamic> json) => BankBalances(
        accounts: ((json['accounts'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(BankAccount.fromJson)
            .toList(),
        total: BankAccount._toDouble(json['total_balance']),
        totalFormatted: json['total_balance_formatted'] as String? ?? '',
      );

  bool get isEmpty => accounts.isEmpty;
}
