/// One person on the payroll, and what a period owes them.
class Employee {
  const Employee({
    required this.id,
    required this.name,
    required this.monthlySalary,
    required this.monthlySalaryFormatted,
    this.advanceOutstanding = 0,
    this.advanceOutstandingFormatted = '',
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

  bool get owesAdvance => advanceOutstanding > 0;

  factory Employee.fromJson(Map<String, dynamic> json) => Employee(
        id: (json['id'] as num).toInt(),
        name: '${json['name'] ?? ''}',
        monthlySalary: _double(json['monthly_salary']),
        monthlySalaryFormatted: '${json['monthly_salary_formatted'] ?? ''}',
        advanceOutstanding: _double(json['advance_outstanding']),
        advanceOutstandingFormatted: '${json['advance_outstanding_formatted'] ?? ''}',
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
    required this.userName,
    required this.periodLabel,
    required this.netAmount,
    required this.netAmountFormatted,
    required this.isPaid,
    this.advanceDeduction = 0,
    this.advanceDeductionFormatted = '',
    this.paidOnJalali,
    this.note,
  });

  final int id;
  final String userName;
  final String periodLabel;
  final double netAmount;
  final String netAmountFormatted;
  final bool isPaid;

  /// How much of this person's advances this slip took back. Carried so the
  /// list can say why a wage came out smaller than the wage.
  final double advanceDeduction;
  final String advanceDeductionFormatted;

  final String? paidOnJalali;
  final String? note;

  bool get recoveredAdvance => advanceDeduction > 0;

  factory Payslip.fromJson(Map<String, dynamic> json) => Payslip(
        id: (json['id'] as num).toInt(),
        userName: '${(json['user'] as Map?)?['name'] ?? ''}',
        periodLabel: '${json['period_label'] ?? ''}',
        netAmount: Employee._double(json['net_amount']),
        netAmountFormatted: '${json['net_amount_formatted'] ?? ''}',
        isPaid: json['is_paid'] == true,
        advanceDeduction: Employee._double(json['advance_deduction']),
        advanceDeductionFormatted: '${json['advance_deduction_formatted'] ?? ''}',
        paidOnJalali: json['paid_on_jalali'] as String?,
        note: json['note'] as String?,
      );
}
