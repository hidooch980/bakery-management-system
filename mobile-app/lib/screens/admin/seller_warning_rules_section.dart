import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// The rules that turn a seller's own records into a warning.
///
/// Every morning each active rule is checked against yesterday, and each
/// hit lands in «درخواست تنبیه» as a pending warning for the manager. A
/// rule never docks pay. The ones marked «نانینو» come from the complaint
/// list in nanino's buyer app; the manager sets thresholds and wording.
class SellerWarningRulesSection extends StatefulWidget {
  const SellerWarningRulesSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<SellerWarningRulesSection> createState() =>
      _SellerWarningRulesSectionState();
}

class _SellerWarningRulesSectionState extends State<SellerWarningRulesSection> {
  late Future<List<Map<String, dynamic>>> _rules;

  @override
  void initState() {
    super.initState();
    _rules = widget.api.sellerWarningRules();
  }

  void _reload() {
    setState(() {
      _rules = widget.api.sellerWarningRules();
    });
  }

  Future<void> _save(Map<String, dynamic> rule, Map<String, dynamic> changes,
      String done) async {
    try {
      await widget.api.updateSellerWarningRule(rule['id'] as int, changes);
      if (!mounted) return;
      showMessage(context, done);
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  Future<void> _edit(Map<String, dynamic> rule) async {
    final changes = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _RuleDialog(rule: rule),
    );
    if (changes == null) return;
    await _save(rule, changes, 'قانون ذخیره شد.');
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _rules,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Padding(
            padding: EdgeInsets.all(16),
            child: Center(child: CircularProgressIndicator()),
          );
        }
        if (snapshot.hasError) {
          return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
        }
        return SellerWarningRulesCard(
          rules: snapshot.data!,
          onToggle: (rule, on) => _save(rule, {'is_active': on},
              on ? 'قانون روشن شد.' : 'قانون خاموش شد.'),
          onEdit: _edit,
        );
      },
    );
  }
}

/// The list itself, drawn apart so it can be shown without a server.
class SellerWarningRulesCard extends StatelessWidget {
  const SellerWarningRulesCard({
    super.key,
    required this.rules,
    required this.onToggle,
    required this.onEdit,
  });

  final List<Map<String, dynamic>> rules;
  final void Function(Map<String, dynamic> rule, bool on) onToggle;
  final ValueChanged<Map<String, dynamic>> onEdit;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final active = rules.where((r) => r['is_active'] == true).length;

    return AdminSection(
      title: 'قوانین اخطار فروشنده',
      icon: Icons.rule_rounded,
      trailing: Text('$active فعال', style: theme.textTheme.bodySmall),
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Text(
            'هر صبح قوانین روشن روی کارکرد دیروز سنجیده می‌شوند و هر مورد '
            'به‌صورت «اخطار در انتظار تأیید» به درخواست‌های تنبیه می‌آید. '
            'چیزی از حقوق کسر نمی‌شود.',
            style: theme.textTheme.bodySmall,
          ),
        ),
        for (final rule in rules)
          ListTile(
            key: Key('seller-rule-${rule['id']}'),
            contentPadding: EdgeInsets.zero,
            onTap: () => onEdit(rule),
            title: Row(children: [
              Expanded(
                child: Text('${rule['name'] ?? ''}',
                    style: theme.textTheme.titleSmall
                        ?.copyWith(fontWeight: FontWeight.w700)),
              ),
              if (rule['source'] == 'nanino')
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: theme.colorScheme.secondaryContainer,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text('نانینو', style: theme.textTheme.labelSmall),
                ),
            ]),
            subtitle: Text(
              '${rule['condition_label'] ?? ''} • مقدار: ${rule['threshold_label'] ?? 'تعیین نشده'}',
            ),
            trailing: Switch(
              key: Key('seller-rule-switch-${rule['id']}'),
              value: rule['is_active'] == true,
              onChanged: (on) => onToggle(rule, on),
            ),
          ),
      ],
    );
  }
}

class _RuleDialog extends StatefulWidget {
  const _RuleDialog({required this.rule});

  final Map<String, dynamic> rule;

  @override
  State<_RuleDialog> createState() => _RuleDialogState();
}

class _RuleDialogState extends State<_RuleDialog> {
  late final TextEditingController _threshold;
  late final TextEditingController _description;
  String? _error;

  @override
  void initState() {
    super.initState();
    final t = widget.rule['threshold'];
    _threshold = TextEditingController(
        text: t == null
            ? ''
            : (t is num && t == t.roundToDouble() ? '${t.toInt()}' : '$t'));
    _description =
        TextEditingController(text: '${widget.rule['description'] ?? ''}');
  }

  @override
  void dispose() {
    _threshold.dispose();
    _description.dispose();
    super.dispose();
  }

  void _submit() {
    final raw = latinDigits(_threshold.text.trim())
        .replaceAll(',', '')
        .replaceAll('٬', '');
    final value = raw.isEmpty ? null : double.tryParse(raw);
    if (raw.isNotEmpty && (value == null || value < 0)) {
      setState(() => _error = 'عدد معتبر و نامنفی وارد کنید.');
      return;
    }
    Navigator.pop(context, {
      'threshold': value,
      'description': _description.text.trim(),
    });
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('${widget.rule['name'] ?? ''}'),
      content: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            key: const Key('seller-rule-threshold'),
            controller: _threshold,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(
              labelText: 'مقدار (${widget.rule['unit_label'] ?? ''})',
              errorText: _error,
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _description,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'متن قانون / توضیح',
              hintText: 'متن قانون را بعداً اینجا بنویسید',
            ),
          ),
        ]),
      ),
      actions: [
        TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('انصراف')),
        FilledButton(onPressed: _submit, child: const Text('ذخیره')),
      ],
    );
  }
}
