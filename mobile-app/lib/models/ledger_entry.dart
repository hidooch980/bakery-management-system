/// A category an income or expense can be filed under, as the shop's
/// settings define it.
class LedgerCategory {
  const LedgerCategory({required this.key, required this.label});

  final String key;
  final String label;

  factory LedgerCategory.fromJson(Map<String, dynamic> json) => LedgerCategory(
        // The two endpoints disagree on the field name, so accept either
        // rather than making the caller care which one it asked.
        key: '${json['key'] ?? json['value'] ?? ''}',
        label: '${json['label'] ?? ''}',
      );
}

/// One money movement the admin recorded by hand — an expense paid or an
/// income taken outside the day's bread sales.
class LedgerEntry {
  const LedgerEntry({
    required this.id,
    required this.title,
    required this.categoryLabel,
    required this.amountFormatted,
    this.dateDisplay,
    this.note,
  });

  final int id;
  final String title;
  final String categoryLabel;
  final String amountFormatted;
  final String? dateDisplay;
  final String? note;

  factory LedgerEntry.fromJson(Map<String, dynamic> json) => LedgerEntry(
        id: (json['id'] as num?)?.toInt() ?? 0,
        title: '${json['title'] ?? ''}',
        categoryLabel: '${json['category_label'] ?? json['category'] ?? ''}',
        amountFormatted: '${json['amount_formatted'] ?? json['amount'] ?? ''}',
        dateDisplay: (json['spent_on_display'] ??
            json['received_on_display'] ??
            json['created_at_display']) as String?,
        note: json['note'] as String?,
      );
}
