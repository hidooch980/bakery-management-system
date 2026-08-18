/// One person on the payroll, and what a period owes them.
class Employee {
  const Employee({
    required this.id,
    required this.name,
    required this.monthlySalary,
    required this.monthlySalaryFormatted,
    this.advanceOutstanding = 0,
    this.advanceOutstandingFormatted = '',
    this.suggestedBankAccountId,
    this.suggestedBonus = 0,
    this.suggestedBonusFormatted = '',
    this.suggestedDeduction = 0,
    this.suggestedDeductionFormatted = '',
    this.adjustmentCount = 0,
    this.hasRequested = false,
    this.requestedDaysAgo,
  });

  final int id;
  final String name;

  /// In the shop's display unit, which is what gets typed back.
  final double monthlySalary;
  final String monthlySalaryFormatted;

  /// What this person has drawn against pay and not yet worked off.
  ///
  /// The payslip has always taken this off — but on the server, after the
  /// button was pressed. The phone confirmed one figure and the shop stored
  /// another, so from where the owner stood the advances were not being
  /// deducted at all.
  final double advanceOutstanding;
  final String advanceOutstandingFormatted;

  /// The account this person's money last came out of, so the sheet opens
  /// on an answer rather than a question. A wage paid from no account
  /// records the cost and moves nothing.
  final int? suggestedBankAccountId;

  /// This month's rewards and penalties, already totalled.
  ///
  /// The pay sheet opens on these instead of on zero. They are suggestions
  /// and nothing more — the server does not add them during save, so the
  /// figure confirmed on screen is the figure stored.
  final double suggestedBonus;
  final String suggestedBonusFormatted;
  final double suggestedDeduction;
  final String suggestedDeductionFormatted;
  final int adjustmentCount;

  bool get owesAdvance => advanceOutstanding > 0;

  /// Whether this person has asked to be paid, and how long ago. The shop
  /// went three weeks without writing a payslip and nobody had a way to say
  /// so except in person.
  final bool hasRequested;
  final int? requestedDaysAgo;

  bool get hasAdjustments => adjustmentCount > 0;

  factory Employee.fromJson(Map<String, dynamic> json) => Employee(
        id: (json['id'] as num).toInt(),
        name: '${json['name'] ?? ''}',
        monthlySalary: _double(json['monthly_salary']),
        monthlySalaryFormatted: '${json['monthly_salary_formatted'] ?? ''}',
        advanceOutstanding: _double(json['advance_outstanding']),
        advanceOutstandingFormatted: '${json['advance_outstanding_formatted'] ?? ''}',
        suggestedBankAccountId: (json['suggested_bank_account_id'] as num?)?.toInt(),
        suggestedBonus: _double(json['suggested_bonus']),
        suggestedBonusFormatted: '${json['suggested_bonus_formatted'] ?? ''}',
        suggestedDeduction: _double(json['suggested_deduction']),
        suggestedDeductionFormatted: '${json['suggested_deduction_formatted'] ?? ''}',
        adjustmentCount: (json['adjustment_count'] as num?)?.toInt() ?? 0,
        hasRequested: json['has_requested'] == true,
        requestedDaysAgo: (json['requested_days_ago'] as num?)?.toInt(),
      );

  static double _double(dynamic value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
}

/// A payslip: what was owed for a period, and whether it has been handed over.
///
/// The net is worked out by the server from base plus bonus, less deductions
/// and less whatever advances this person still owes — never typed. Three
/// parts and a total that can disagree is how a payroll stops being trusted,
/// and a fourth part the screen never mentioned is how it stopped.
class Payslip {
  const Payslip({
    required this.id,
    required this.userId,
    required this.userName,
    required this.periodLabel,
    required this.periodStartJalali,
    required this.netAmount,
    required this.netAmountFormatted,
    required this.isPaid,
    this.advanceDeduction = 0,
    this.advanceDeductionFormatted = '',
    this.bankAccountTitle,
    this.paidOnJalali,
    this.note,
  });

  final int id;
  final int userId;
  final String userName;
  final String periodLabel;

  /// The period this slip is for, as ۱۴۰۵/۰۵/۰۱ — the form the phone builds
  /// when it writes one. Whether somebody has been paid is a question about
  /// a person and a period, and answering it off the newest label on file
  /// marks everyone paid last month as paid this month.
  final String periodStartJalali;

  final double netAmount;
  final String netAmountFormatted;
  final bool isPaid;

  /// How much of this person's advances this slip took back. Carried so the
  /// list can say why a wage came out smaller than the wage.
  final double advanceDeduction;
  final String advanceDeductionFormatted;

  final String? paidOnJalali;
  final String? note;

  /// Which account it was paid from, or null when it came out of the till.
  final String? bankAccountTitle;

  bool get recoveredAdvance => advanceDeduction > 0;

  factory Payslip.fromJson(Map<String, dynamic> json) => Payslip(
        id: (json['id'] as num).toInt(),
        userId: ((json['user'] as Map?)?['id'] as num?)?.toInt() ?? 0,
        userName: '${(json['user'] as Map?)?['name'] ?? ''}',
        periodLabel: '${json['period_label'] ?? ''}',
        periodStartJalali: '${json['period_start_jalali'] ?? ''}',
        netAmount: Employee._double(json['net_amount']),
        netAmountFormatted: '${json['net_amount_formatted'] ?? ''}',
        isPaid: json['is_paid'] == true,
        advanceDeduction: Employee._double(json['advance_deduction']),
        advanceDeductionFormatted: '${json['advance_deduction_formatted'] ?? ''}',
        bankAccountTitle: json['bank_account_title'] as String?,
        paidOnJalali: json['paid_on_jalali'] as String?,
        note: json['note'] as String?,
      );
}
