/// A named buyer — a school, a government office, or a regular credit
/// customer — that a sale can be attributed to.
class Customer {
  const Customer({
    required this.id,
    required this.name,
    required this.type,
    required this.typeLabel,
    this.contactName,
    this.phone,
  });

  final int id;
  final String name;
  final String type;
  final String typeLabel;
  final String? contactName;
  final String? phone;

  bool get isSchool => type == 'school';

  factory Customer.fromJson(Map<String, dynamic> json) => Customer(
        id: json['id'] as int,
        name: json['name'] as String? ?? '',
        type: json['type'] as String? ?? 'other',
        typeLabel: json['type_label'] as String? ?? '',
        contactName: json['contact_name'] as String?,
        phone: json['phone'] as String?,
      );
}
