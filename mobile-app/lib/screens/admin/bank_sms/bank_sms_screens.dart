import 'package:flutter/material.dart';

import '../../../theme/app_theme.dart';
import '../../../utils/bank_sms_parser.dart';
import '../../../utils/formatters.dart';

/// «پیامک‌های بانکی»: پیامک بانک می‌آید، این‌جا تأیید می‌شود، و فقط آن‌وقت
/// درآمد یا هزینه می‌شود. هیچ پیامکی خودبه‌خود در دفتر نمی‌نشیند.
///
/// مرحلهٔ طراحی: صفحه‌ها با داده‌ای که از بیرون داده می‌شود کار می‌کنند تا
/// نمونهٔ تصویری ساخته شود؛ اتصال به API در مرحلهٔ ساخت می‌آید.
class BankSms {
  const BankSms({
    required this.id,
    required this.bank,
    required this.body,
    required this.receivedAt,
    required this.amountToman,
    required this.direction,
    this.cardLast4,
    this.accountName,
    this.suggestedCategory,
    this.status = 'pending',
    this.matchedLabel,
    this.candidates = const [],
    this.possibleDuplicateOf,
  });

  /// «حقوق — جلیل درازهی»: the already-recorded movement it matched.
  final String? matchedLabel;

  /// More than one recorded movement could be it — someone picks.
  final List<String> candidates;

  /// Another SMS with the same amount the same day — maybe sent twice.
  final String? possibleDuplicateOf;

  /// Waiting for someone: pending, or ambiguous.
  bool get isOpen => status == 'pending' || status == 'ambiguous';

  final int id;
  final String bank;
  final String body;
  final DateTime receivedAt;
  final int amountToman;
  final BankSmsDirection direction;
  final String? cardLast4;

  /// حساب بانکی نانوایی که این کارت به آن وصل شده؛ null یعنی هنوز وصل نیست.
  final String? accountName;
  final String? suggestedCategory;
  final String status;

  bool get isIncome => direction == BankSmsDirection.deposit;
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
  'sale': 'فروش نان (کارتخوان)',
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
    this.compact = false,
  });

  final BankSms sms;
  final VoidCallback? onConfirm;
  final VoidCallback? onIgnore;
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
                    'بانک ${sms.bank}${sms.cardLast4 != null ? '  •  کارت …${sms.cardLast4}' : ''}',
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
                Text(
                  'پیشنهاد: ${sms.isIncome ? 'درآمد' : 'هزینه'} — ${cats[sms.suggestedCategory] ?? 'سایر'}',
                  style: text.bodySmall,
                ),
                const Spacer(),
                if (sms.accountName == null)
                  Text('کارت وصل نشده',
                      style: text.labelSmall?.copyWith(color: scheme.error))
                else
                  Text(sms.accountName!,
                      style: text.labelSmall
                          ?.copyWith(color: scheme.onSurfaceVariant)),
              ],
            ),
            if (!compact) ...[
              const SizedBox(height: 8),
              Text(sms.body,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style:
                      text.bodySmall?.copyWith(color: scheme.onSurfaceVariant)),
            ],
            if (sms.possibleDuplicateOf != null) ...[
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
                    child: Text('شاید تکراری: ${sms.possibleDuplicateOf}',
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
                    onPressed: () {},
                    icon: const Icon(Icons.link_rounded, size: IconSize.row),
                    label: Text('همین است: $c'),
                  ),
                ),
              Row(children: [
                Expanded(
                  child: TextButton(
                      onPressed: onConfirm ?? () {},
                      child: const Text('هیچ‌کدام — ثبت جدید')),
                ),
                TextButton(
                    onPressed: onIgnore ?? () {}, child: const Text('نادیده')),
              ]),
            ] else if (sms.status == 'pending') ...[
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                          minimumSize: const Size(0, 44)),
                      onPressed: onConfirm ?? () {},
                      child: Text(sms.isIncome ? 'ثبت درآمد' : 'ثبت هزینه'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  OutlinedButton(
                    style: OutlinedButton.styleFrom(
                        minimumSize: const Size(96, 44)),
                    onPressed: onIgnore ?? () {},
                    child: const Text('نادیده'),
                  ),
                ],
              ),
            ] else
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  sms.status == 'confirmed' ? '✓ ثبت شد' : 'نادیده گرفته شد',
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

// -------------------------------------------------------------------- inbox

class BankSmsInboxScreen extends StatelessWidget {
  const BankSmsInboxScreen({super.key, required this.items});

  final List<BankSms> items;

  @override
  Widget build(BuildContext context) {
    final pending = items.where((s) => s.isOpen).toList();
    final done = items.where((s) => !s.isOpen).toList();
    final inSum = pending
        .where((s) => s.isIncome)
        .fold<int>(0, (a, s) => a + s.amountToman);
    final outSum = pending
        .where((s) => !s.isIncome)
        .fold<int>(0, (a, s) => a + s.amountToman);

    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('پیامک‌های بانکی'),
          actions: [
            IconButton(
                onPressed: () {},
                tooltip: 'تنظیمات',
                icon: const Icon(Icons.tune_rounded)),
          ],
          bottom: TabBar(tabs: [
            Tab(
                text:
                    'در انتظار تأیید (${persianDigits('${pending.length}')})'),
            const Tab(text: 'ثبت‌شده و نادیده'),
          ]),
        ),
        body: TabBarView(children: [
          ListView(
            padding: const EdgeInsets.all(12),
            children: [
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
              const SizedBox(height: 8),
              for (final s in pending) BankSmsTile(sms: s),
            ],
          ),
          ListView(
            padding: const EdgeInsets.all(12),
            children: [
              for (final s in done) BankSmsTile(sms: s, compact: true)
            ],
          ),
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

class BankSmsConfirmSheet extends StatelessWidget {
  const BankSmsConfirmSheet(
      {super.key, required this.sms, this.selected, this.onSelect});

  final BankSms sms;
  final String? selected;
  final ValueChanged<String>? onSelect;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final scheme = Theme.of(context).colorScheme;
    final cats = sms.isIncome ? incomeCategories : expenseCategories;
    final chosen = selected ?? sms.suggestedCategory ?? 'other';
    final color = sms.isIncome ? AppColors.moneyIn : AppColors.moneyOut;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(18),
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
                'بانک ${sms.bank} • کارت …${sms.cardLast4 ?? '—'} • ${JalaliFormat.dateTime(sms.receivedAt)}',
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
                    selected: e.key == chosen,
                    onSelected: (_) => onSelect?.call(e.key),
                  ),
              ],
            ),
            const SizedBox(height: 14),
            InputDecorator(
              decoration: const InputDecoration(
                  labelText: 'حساب بانکی', border: OutlineInputBorder()),
              child: Text(sms.accountName ?? 'انتخاب کنید'),
            ),
            const SizedBox(height: 10),
            TextField(
              decoration: const InputDecoration(
                  labelText: 'توضیح (اختیاری)', border: OutlineInputBorder()),
              controller:
                  TextEditingController(text: sms.isIncome ? '' : 'حمل نمک'),
            ),
            const SizedBox(height: 14),
            FilledButton(
                onPressed: () {},
                child: Text(sms.isIncome ? 'ثبت درآمد' : 'ثبت هزینه')),
            const SizedBox(height: 8),
            TextButton(onPressed: () {}, child: const Text('نادیده بگیر')),
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
  const BankSmsPendingPopup({super.key, required this.items, this.onLater});

  final List<BankSms> items;
  final VoidCallback? onLater;

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
                  for (final s in items) BankSmsTile(sms: s, compact: true)
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(14),
              child: OutlinedButton(
                onPressed: onLater ?? () {},
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
  const BankSmsHomeCard({super.key, required this.pending});

  final int pending;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: ListTile(
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

class BankSmsSettingsScreen extends StatelessWidget {
  const BankSmsSettingsScreen(
      {super.key, required this.cards, required this.senders});

  /// (چهار رقم آخر، بانک، حساب نانوایی یا null)
  final List<(String, String, String?)> cards;
  final List<(String, String)> senders;

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
                value: true,
                onChanged: (_) {},
                title: const Text('خواندن پیامک بانک روی این گوشی'),
                subtitle: const Text('فقط برای مدیر'),
              ),
              SwitchListTile(
                value: true,
                onChanged: (_) {},
                title: const Text('نمایش خودکار هنگام باز کردن برنامه'),
                subtitle: const Text('اگر پیامکِ منتظر ثبت باشد'),
              ),
              SwitchListTile(
                value: false,
                onChanged: (_) {},
                title: const Text('تأیید خودکار موارد تکراری'),
                subtitle: const Text('پیشنهادی — فعلاً خاموش'),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 14, 8, 6),
            child: Text('کارت‌ها و حساب‌های نانوایی',
                style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          ),
          Card(
            child: Column(children: [
              for (final c in cards)
                ListTile(
                  leading: const Icon(Icons.credit_card_rounded),
                  title: Text('کارت …${c.$1}  •  ${c.$2}'),
                  subtitle: Text(c.$3 ?? 'به حسابی وصل نشده',
                      style:
                          TextStyle(color: c.$3 == null ? scheme.error : null)),
                  trailing: TextButton(
                      onPressed: () {},
                      child: Text(c.$3 == null ? 'وصل کن' : 'تغییر')),
                ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 14, 8, 6),
            child: Text('شماره‌های پیامکی بانک',
                style: text.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          ),
          Card(
            child: Column(children: [
              for (final s in senders)
                ListTile(
                  dense: true,
                  leading: const Icon(Icons.account_balance_rounded),
                  title: Text(s.$2),
                  trailing: Text(s.$1),
                ),
              ListTile(
                leading: const Icon(Icons.add_rounded),
                title: const Text('افزودن شمارهٔ بانک دیگر'),
                onTap: () {},
              ),
            ]),
          ),
        ],
      ),
    );
  }
}
