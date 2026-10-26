import 'package:flutter/material.dart';

import '../../../theme/app_theme.dart';
import '../../../utils/formatters.dart';
import '../../../utils/json.dart';

/// «پیامک‌های بانکی»: پیامک بانک می‌آید، این‌جا تأیید می‌شود، و فقط آن‌وقت
/// درآمد یا هزینه می‌شود. هیچ پیامکی خودبه‌خود در دفتر نمی‌نشیند.
///
/// این فایل فقط ظاهر است؛ صفحه‌هایی که داده را از سرور می‌آورند و دکمه‌ها را
/// به API وصل می‌کنند در bank_sms_pages.dart هستند.
class BankSms {
  const BankSms({
    required this.id,
    required this.bank,
    required this.body,
    required this.receivedAt,
    required this.amountToman,
    required this.isIncome,
    this.last4,
    this.accountId,
    this.accountName,
    this.suggestedCategory,
    this.status = 'pending',
    this.matchedLabel,
    this.candidates = const [],
    this.possibleDuplicateAt,
  });

  factory BankSms.fromJson(Map<String, dynamic> j) => BankSms(
        id: (j['id'] as num).toInt(),
        bank: j['bank'] as String?,
        body: j['body'] as String? ?? '',
        receivedAt: DateTime.parse(j['received_at'] as String).toLocal(),
        amountToman: (j['amount'] as num).round(),
        isIncome: j['direction'] == 'in',
        last4: j['last4'] as String?,
        accountId: (j['bank_account_id'] as num?)?.toInt(),
        accountName: j['account_name'] as String?,
        suggestedCategory: j['suggested_category'] as String?,
        status: j['status'] as String? ?? 'pending',
        matchedLabel: j['matched_label'] as String?,
        candidates: [
          for (final c in rowList(j['candidates']))
            (id: (c['id'] as num).toInt(), label: '${c['label']}'),
        ],
        possibleDuplicateAt:
            keyedGroup(j['possible_duplicate_of'])['at'] as String?,
      );

  final int id;
  final String? bank;

  /// Account numbers arrive masked: «حساب ****7890».
  final String body;
  final DateTime receivedAt;
  final int amountToman;
  final bool isIncome;

  /// Last four digits of the account (Sepah) or card the SMS names.
  final String? last4;

  /// The shop's account those digits are tied to; null means not yet.
  final int? accountId;
  final String? accountName;
  final String? suggestedCategory;
  final String status;

  /// «حقوق — جلیل درازهی»: the already-recorded movement it matched.
  final String? matchedLabel;

  /// More than one recorded movement could be it — someone picks.
  final List<({int id, String label})> candidates;

  /// Another SMS with the same amount minutes apart — maybe sent twice.
  final String? possibleDuplicateAt;

  /// Waiting for someone: pending, or ambiguous.
  bool get isOpen => status == 'pending' || status == 'ambiguous';
}

const expenseCategories = <String, String>{
  'freight': 'حمل و نقل',
  'unloading': 'تخلیه و باربری',
  'fuel': 'سوخت',
  'utilities': 'آب، برق، گاز',
  'rent': 'اجاره',
  'maintenance': 'تعمیرات',
  'equipment': 'تجهیزات',
  'packaging': 'بسته‌بندی',
  'insurance': 'بیمه',
  'tax': 'مالیات و عوارض',
  'salary': 'حقوق کارکنان',
  'other': 'سایر',
};

const incomeCategories = <String, String>{
  'card_sale': 'فروش نان (کارتخوان)',
  'subsidy': 'یارانه و کمک دولتی',
  'rent': 'اجاره',
  'scrap': 'فروش ضایعات و کیسه',
  'service': 'خدمات',
  'partner': 'تسویه همکار',
  'other': 'سایر',
};

String _amount(int toman) => MoneyFormat.format(toman, currency: Currency.rial);

// ---------------------------------------------------------------- permission

class BankSmsPermissionScreen extends StatelessWidget {
  const BankSmsPermissionScreen({super.key, this.onAllow, this.onLater});

  final VoidCallback? onAllow;
  final VoidCallback? onLater;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final text = Theme.of(context).textTheme;

    Widget point(IconData icon, String title, String body) => Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, color: scheme.primary, size: IconSize.heading),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title,
                        style: text.titleSmall
                            ?.copyWith(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text(body,
                        style: text.bodySmall
                            ?.copyWith(color: scheme.onSurfaceVariant)),
                  ],
                ),
              ),
            ],
          ),
        );

    return Scaffold(
      appBar: AppBar(title: const Text('پیامک‌های بانکی')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Center(
              child: Container(
                width: 84,
                height: 84,
                decoration: BoxDecoration(
                  color: scheme.primary.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(Corner.control),
                ),
                child: Icon(Icons.sms_rounded, size: 44, color: scheme.primary),
              ),
            ),
            const SizedBox(height: 16),
            Text('ثبت درآمد و هزینه از پیامک بانک',
                textAlign: TextAlign.center,
                style: text.titleLarge?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            Text(
              'برای این کار برنامه باید پیامک‌ها را بخواند. فقط پیامک بانک‌ها خوانده می‌شود.',
              textAlign: TextAlign.center,
              style: text.bodyMedium?.copyWith(color: scheme.onSurfaceVariant),
            ),
            const SizedBox(height: 24),
            point(Icons.account_balance_rounded, 'فقط پیامک بانک',
                'پیامک‌های شخصی، رمز پویا و کد تأیید هرگز خوانده یا فرستاده نمی‌شوند.'),
            point(Icons.fact_check_rounded, 'هیچ چیز خودکار ثبت نمی‌شود',
                'هر پیامک اول در «صندوق تأیید» می‌آید؛ شما با یک ضربه درآمد یا هزینه‌اش می‌کنید یا نادیده می‌گیرید.'),
            point(Icons.admin_panel_settings_rounded, 'فقط مدیر',
                'این بخش فقط روی گوشی مدیر روشن می‌شود و هر وقت بخواهید خاموشش می‌کنید.'),
            point(Icons.cloud_off_rounded, 'بی‌اینترنت هم',
                'اگر اینترنت نباشد، پیامک نگه داشته می‌شود و بعداً فرستاده می‌شود.'),
            const SizedBox(height: 12),
            FilledButton.icon(
              onPressed: onAllow ?? () {},
              icon: const Icon(Icons.check_rounded),
              label: const Text('اجازه خواندن پیامک بانک'),
            ),
            const SizedBox(height: 10),
            OutlinedButton(
                onPressed: onLater ?? () {}, child: const Text('بعداً')),
          ],
        ),
      ),
    );
  }
}

// --------------------------------------------------------------------- tile

class BankSmsTile extends StatelessWidget {
  const BankSmsTile({
    super.key,
    required this.sms,
    this.onConfirm,
    this.onIgnore,
    this.onPick,
    this.compact = false,
  });

  final BankSms sms;
  final VoidCallback? onConfirm;
  final VoidCallback? onIgnore;
  final ValueChanged<int>? onPick;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final text = Theme.of(context).textTheme;
    final color = sms.isIncome ? AppColors.moneyIn : AppColors.moneyOut;
    final cats = sms.isIncome ? incomeCategories : expenseCategories;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(Corner.hair * 2),
                  ),
                  child: Text(sms.isIncome ? 'واریز' : 'برداشت',
                      style:
                          TextStyle(color: color, fontWeight: FontWeight.w700)),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    '${sms.bank != null ? 'بانک ${sms.bank}' : 'بانک'}${sms.last4 != null ? '  •  حساب …${persianDigits(sms.last4!)}' : ''}',
                    style: text.bodySmall
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                ),
                Text(JalaliFormat.dateTime(sms.receivedAt),
                    style: text.labelSmall
                        ?.copyWith(color: scheme.onSurfaceVariant)),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              _amount(sms.amountToman),
              style: text.titleLarge
                  ?.copyWith(color: color, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 4),
            Row(
              children: [
                Icon(Icons.label_outline_rounded,
                    size: IconSize.inline, color: scheme.onSurfaceVariant),
                const SizedBox(width: 4),
                Flexible(
                  child: Text(
                    '${sms.isOpen ? 'پیشنهاد: ' : ''}${sms.isIncome ? 'درآمد' : 'هزینه'} — ${cats[sms.suggestedCategory] ?? 'سایر'}',
                    style: text.bodySmall,
                  ),
                ),
                const SizedBox(width: 8),
                if (sms.accountName == null)
                  Text('حساب وصل نشده',
                      style: text.labelSmall?.copyWith(color: scheme.error))
                else
                  Flexible(
                    child: Text(sms.accountName!,
                        overflow: TextOverflow.ellipsis,
                        style: text.labelSmall
                            ?.copyWith(color: scheme.onSurfaceVariant)),
                  ),
              ],
            ),
            if (!compact) ...[
              const SizedBox(height: 8),
              Text(sms.body,
                  maxLines: 3,
                  overflow: TextOverflow.ellipsis,
                  style:
                      text.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
            ],
            if (sms.isOpen && sms.possibleDuplicateAt != null) ...[
              const SizedBox(height: 8),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.amber.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(Corner.hair * 2),
                ),
                child: Row(children: [
                  const Icon(Icons.warning_amber_rounded,
                      color: Colors.amber, size: IconSize.inline),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                        'شاید تکراری: همین مبلغ ساعت ${persianDigits(sms.possibleDuplicateAt!)} هم آمده',
                        style: text.bodySmall),
                  ),
                ]),
              ),
            ],
            if (sms.status == 'matched')
              Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Row(children: [
                  Container(
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: AppColors.moneyIn.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(Corner.hair * 2),
                    ),
                    child: const Text('قبلاً ثبت شده',
                        style: TextStyle(
                            color: AppColors.moneyIn,
                            fontWeight: FontWeight.w700)),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                      child:
                          Text(sms.matchedLabel ?? '', style: text.bodySmall)),
                ]),
              )
            else if (sms.status == 'ambiguous') ...[
              const SizedBox(height: 10),
              Text('چند ثبت با همین مبلغ هست؛ کدام است؟',
                  style: text.labelMedium?.copyWith(
                      color: Colors.amber, fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              for (final c in sms.candidates)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                        minimumSize: const Size(0, 40),
                        alignment: AlignmentDirectional.centerStart),
                    onPressed: onPick == null ? null : () => onPick!(c.id),
                    icon: const Icon(Icons.link_rounded, size: IconSize.row),
                    label: Text('همین است: ${c.label}'),
                  ),
                ),
              Row(children: [
                Expanded(
                  child: TextButton(
                      onPressed: onConfirm,
                      child: const Text('هیچ‌کدام — ثبت جدید')),
                ),
                TextButton(onPressed: onIgnore, child: const Text('نادیده')),
              ]),
            ] else if (sms.status == 'pending') ...[
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                          minimumSize: const Size(0, 44)),
                      onPressed: onConfirm,
                      child: Text(sms.isIncome ? 'ثبت درآمد' : 'ثبت هزینه'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  OutlinedButton(
                    style: OutlinedButton.styleFrom(
                        minimumSize: const Size(96, 44)),
                    onPressed: onIgnore,
                    child: const Text('نادیده'),
                  ),
                ],
              ),
            ] else
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  sms.status == 'confirmed'
                      ? '✓ ثبت شد${sms.matchedLabel != null ? ' — ${sms.matchedLabel}' : ''}'
                      : 'نادیده گرفته شد',
                  style: text.labelMedium?.copyWith(
                      color: sms.status == 'confirmed'
                          ? AppColors.moneyIn
                          : scheme.onSurfaceVariant),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// What a tile's buttons do; the pages fill it in.
class BankSmsActions {
  const BankSmsActions({this.confirm, this.ignore, this.pick});

  final void Function(BankSms sms)? confirm;
  final void Function(BankSms sms)? ignore;
  final void Function(BankSms sms, int transactionId)? pick;

  BankSmsTile tile(BankSms s, {bool compact = false}) => BankSmsTile(
        key: ValueKey('sms-${s.id}'),
        sms: s,
        compact: compact,
        onConfirm: confirm == null ? null : () => confirm!(s),
        onIgnore: ignore == null ? null : () => ignore!(s),
        onPick: pick == null ? null : (id) => pick!(s, id),
      );
}

// -------------------------------------------------------------------- inbox

class BankSmsInboxScreen extends StatelessWidget {
  const BankSmsInboxScreen({
    super.key,
    required this.open,
    required this.done,
    this.actions = const BankSmsActions(),
    this.onSettings,
    this.onRefresh,
    this.loading = false,
    this.error,
  });

  final List<BankSms> open;
  final List<BankSms> done;
  final BankSmsActions actions;
  final VoidCallback? onSettings;
  final Future<void> Function()? onRefresh;
  final bool loading;
  final String? error;

  @override
  Widget build(BuildContext context) {
    final inSum = open
        .where((s) => s.isIncome && s.status == 'pending')
        .fold<int>(0, (a, s) => a + s.amountToman);
    final outSum = open
        .where((s) => !s.isIncome && s.status == 'pending')
        .fold<int>(0, (a, s) => a + s.amountToman);

    Widget list(List<Widget> children, String empty) => RefreshIndicator(
          onRefresh: onRefresh ?? () async {},
          child: ListView(
            padding: const EdgeInsets.all(12),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              if (error != null)
                Card(
                    child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Text(error!))),
              if (loading) const LinearProgressIndicator(),
              ...children,
              if (!loading && children.length <= 1)
                Padding(
                  padding: const EdgeInsets.all(32),
                  child: Text(empty, textAlign: TextAlign.center),
                ),
            ],
          ),
        );

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('پیامک‌های بانکی'),
          actions: [
            IconButton(
                onPressed: onSettings,
                tooltip: 'تنظیمات',
                icon: const Icon(Icons.tune_rounded)),
          ],
          bottom: TabBar(tabs: [
            Tab(text: 'در انتظار تأیید (${persianDigits('${open.length}')})'),
            const Tab(text: 'ثبت‌شده و نادیده'),
          ]),
        ),
        body: TabBarView(children: [
          list([
            Row(children: [
              Expanded(
                  child: _SumBox(
                      label: 'واریزهای در انتظار',
                      value: _amount(inSum),
                      color: AppColors.moneyIn)),
              const SizedBox(width: 10),
              Expanded(
                  child: _SumBox(
                      label: 'برداشت‌های در انتظار',
                      value: _amount(outSum),
                      color: AppColors.moneyOut)),
            ]),
            for (final s in open) actions.tile(s),
          ], 'پیامکی منتظر ثبت نیست.'),
          list([
            const SizedBox.shrink(),
            for (final s in done) actions.tile(s, compact: true),
          ], 'هنوز چیزی ثبت یا نادیده نشده.'),
        ]),
      ),
    );
  }
}

class _SumBox extends StatelessWidget {
  const _SumBox(
      {required this.label, required this.value, required this.color});

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: Theme.of(context).textTheme.labelMedium),
              const SizedBox(height: 4),
              Text(value,
                  style: TextStyle(
                      color: color, fontWeight: FontWeight.w800, fontSize: 15)),
            ],
          ),
        ),
      );
}

// ----------------------------------------------------------- confirm sheet

/// What the owner chose in the sheet.
typedef BankSmsChoice = ({String category, int? accountId, String note});

class BankSmsConfirmSheet extends StatefulWidget {
  const BankSmsConfirmSheet({
    super.key,
    required this.sms,
    this.accounts = const {},
    this.onSubmit,
    this.onIgnore,
    this.initialNote = '',
  });

  final BankSms sms;

  /// id → title of the shop's accounts, for an SMS whose digits are not
  /// tied to one yet.
  final Map<int, String> accounts;
  final Future<void> Function(BankSmsChoice choice)? onSubmit;
  final VoidCallback? onIgnore;
  final String initialNote;

  @override
  State<BankSmsConfirmSheet> createState() => _BankSmsConfirmSheetState();
}

class _BankSmsConfirmSheetState extends State<BankSmsConfirmSheet> {
  late String _category = widget.sms.suggestedCategory ?? 'other';
  late int? _account = widget.sms.accountId;
  late final _note = TextEditingController(text: widget.initialNote);
  bool _busy = false;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final sms = widget.sms;
    final text = Theme.of(context).textTheme;
    final scheme = Theme.of(context).colorScheme;
    final cats = sms.isIncome ? incomeCategories : expenseCategories;
    final color = sms.isIncome ? AppColors.moneyIn : AppColors.moneyOut;

    return SafeArea(
      child: SingleChildScrollView(
        padding: EdgeInsets.fromLTRB(
            18, 18, 18, 18 + MediaQuery.viewInsetsOf(context).bottom),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(sms.isIncome ? 'ثبت به‌عنوان درآمد' : 'ثبت به‌عنوان هزینه',
                style: text.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(_amount(sms.amountToman),
                style: text.headlineSmall
                    ?.copyWith(color: color, fontWeight: FontWeight.w800)),
            Text(
                'بانک ${sms.bank ?? '—'} • حساب …${persianDigits(sms.last4 ?? '—')} • ${JalaliFormat.dateTime(sms.receivedAt)}',
                style:
                    text.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
            const SizedBox(height: 14),
            Text('دسته', style: text.labelLarge),
            const SizedBox(height: 6),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final e in cats.entries)
                  ChoiceChip(
                    label: Text(e.value),
                    selected: e.key == _category,
                    onSelected: (_) => setState(() => _category = e.key),
                  ),
              ],
            ),
            const SizedBox(height: 14),
            if (sms.accountId != null || widget.accounts.isEmpty)
              InputDecorator(
                decoration: const InputDecoration(
                    labelText: 'حساب بانکی', border: OutlineInputBorder()),
                child: Text(sms.accountName ?? 'حساب وصل نشده'),
              )
            else
              DropdownButtonFormField<int>(
                initialValue: _account,
                decoration: const InputDecoration(
                    labelText: 'حساب بانکی', border: OutlineInputBorder()),
                items: [
                  for (final a in widget.accounts.entries)
                    DropdownMenuItem(value: a.key, child: Text(a.value)),
                ],
                onChanged: (v) => setState(() => _account = v),
              ),
            const SizedBox(height: 10),
            TextField(
              controller: _note,
              decoration: const InputDecoration(
                  labelText: 'توضیح (اختیاری)', border: OutlineInputBorder()),
            ),
            const SizedBox(height: 14),
            FilledButton(
                onPressed: _busy || widget.onSubmit == null || _account == null
                    ? null
                    : () async {
                        setState(() => _busy = true);
                        try {
                          await widget.onSubmit!((
                            category: _category,
                            accountId: _account,
                            note: _note.text.trim(),
                          ));
                        } finally {
                          if (mounted) setState(() => _busy = false);
                        }
                      },
                child: Text(sms.isIncome ? 'ثبت درآمد' : 'ثبت هزینه')),
            const SizedBox(height: 8),
            TextButton(
                onPressed: _busy ? null : widget.onIgnore,
                child: const Text('نادیده بگیر')),
          ],
        ),
      ),
    );
  }
}

// --------------------------------------------- popup when the app is opened

/// «وقتی برنامه باز بشه نمایش بده تا ثبت کنم»: اگر پیامکِ در انتظاری هست،
/// با باز شدن یا برگشتن برنامه به جلو، همین صفحه بالا می‌آید.
class BankSmsPendingPopup extends StatelessWidget {
  const BankSmsPendingPopup({
    super.key,
    required this.items,
    this.onLater,
    this.actions = const BankSmsActions(),
  });

  final List<BankSms> items;
  final VoidCallback? onLater;
  final BankSmsActions actions;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      body: SafeArea(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 18, 18, 6),
              child: Row(
                children: [
                  Icon(Icons.mark_email_unread_rounded,
                      color: scheme.primary, size: IconSize.large),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                            '${persianDigits('${items.length}')} پیامک بانکی منتظر ثبت',
                            style: text.titleMedium
                                ?.copyWith(fontWeight: FontWeight.w800)),
                        Text('هر کدام را با یک ضربه ثبت کنید یا نادیده بگیرید.',
                            style: text.bodySmall
                                ?.copyWith(color: scheme.onSurfaceVariant)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.symmetric(horizontal: 12),
                children: [
                  for (final s in items) actions.tile(s, compact: true)
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(14),
              child: OutlinedButton(
                onPressed: onLater,
                child: const Text('بعداً'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ------------------------------------------------------------- home badge

class BankSmsHomeCard extends StatelessWidget {
  const BankSmsHomeCard({super.key, required this.pending, this.onTap});

  final int pending;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: ListTile(
        onTap: onTap,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        leading: Badge(
          isLabelVisible: pending > 0,
          label: Text(persianDigits('$pending')),
          child: Icon(Icons.sms_rounded, color: scheme.primary),
        ),
        title: const Text('پیامک‌های بانکی',
            style: TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(pending > 0
            ? '${persianDigits('$pending')} پیامک منتظر تأیید'
            : 'همه ثبت شده‌اند'),
        trailing: const Icon(Icons.chevron_left_rounded),
      ),
    );
  }
}

// --------------------------------------------------------------- settings

typedef BankSmsMapping = ({int id, String last4, String? accountName});

class BankSmsSettingsScreen extends StatelessWidget {
  const BankSmsSettingsScreen({
    super.key,
    required this.enabled,
    required this.permitted,
    required this.showOnOpen,
    required this.mappings,
    this.unmapped = const [],
    this.accounts = const {},
    this.onEnabled,
    this.onShowOnOpen,
    this.onMap,
    this.onUnmap,
  });

  final bool enabled;
  final bool permitted;
  final bool showOnOpen;
  final List<BankSmsMapping> mappings;

  /// Digits seen in SMS that no account is tied to yet.
  final List<String> unmapped;
  final Map<int, String> accounts;
  final ValueChanged<bool>? onEnabled;
  final ValueChanged<bool>? onShowOnOpen;

  /// Called with prefilled digits, or null for «افزودن».
  final void Function(String? last4)? onMap;
  final void Function(BankSmsMapping m)? onUnmap;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: const Text('تنظیمات پیامک بانکی')),
      body: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          Card(
            child: Column(children: [
              SwitchListTile(
                value: enabled,
                onChanged: onEnabled,
                title: const Text('خواندن پیامک‌های تازهٔ بانک روی این گوشی'),
                subtitle: Text(enabled && !permitted
                    ? 'اجازهٔ دریافت پیامک داده نشده'
                    : 'فقط مدیر • فقط پیامک‌هایی که از این به بعد می‌رسند'),
              ),
              SwitchListTile(
                value: showOnOpen,
                onChanged: onShowOnOpen,
                title: const Text('نمایش خودکار هنگام باز کردن برنامه'),
                subtitle: const Text('اگر پیامکِ منتظر ثبت باشد'),
              ),
              const SwitchListTile(
                value: false,
                onChanged: null,
                title: Text('تأیید خودکار'),
                subtitle: Text('خاموش — هر پیامک را خودتان تأیید می‌کنید'),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 14, 8, 6),
            child: Text('حساب‌ها و کارت‌های نانوایی',
                style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 0, 8, 6),
            child: Text(
                'بانک سپه در پیامک شمارهٔ حساب را می‌نویسد. چهار رقم آخر حساب (یا کارت) را به حساب نانوایی وصل کنید.',
                style:
                    text.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
          ),
          Card(
            child: Column(children: [
              for (final m in mappings)
                ListTile(
                  leading: const Icon(Icons.account_balance_rounded),
                  title: Text('حساب …${persianDigits(m.last4)}'),
                  subtitle: Text(m.accountName ?? '—'),
                  trailing: TextButton(
                      style:
                          TextButton.styleFrom(minimumSize: const Size(0, 40)),
                      onPressed: onUnmap == null ? null : () => onUnmap!(m),
                      child: const Text('برداشتن')),
                ),
              for (final d in unmapped)
                ListTile(
                  leading: const Icon(Icons.link_off_rounded),
                  title: Text('حساب …${persianDigits(d)}'),
                  subtitle: Text('در پیامک‌ها آمده، به حسابی وصل نیست',
                      style: TextStyle(color: scheme.error)),
                  trailing: TextButton(
                      style:
                          TextButton.styleFrom(minimumSize: const Size(0, 40)),
                      onPressed: onMap == null ? null : () => onMap!(d),
                      child: const Text('وصل کن')),
                ),
              ListTile(
                leading: const Icon(Icons.add_rounded),
                title: const Text('وصل کردن حساب یا کارت'),
                onTap: onMap == null ? null : () => onMap!(null),
              ),
            ]),
          ),
        ],
      ),
    );
  }
}
