import 'dart:async';

import 'package:flutter/material.dart';

import '../../../services/api_client.dart';
import '../../../services/bakery_api.dart';
import '../../../services/bank_sms_service.dart';
import '../../../utils/json.dart';
import 'bank_sms_screens.dart';

/// One place that knows what is waiting, so the home badge, the popup and
/// the inbox never disagree.
class BankSmsHub extends ChangeNotifier {
  BankSmsHub(BakeryApi api) : service = BankSmsService(api.client);

  static BankSmsHub? _shared;
  static BakeryApi? _sharedApi;

  /// The one the home, the popup and the finance tab share.
  static BankSmsHub of(BakeryApi api) {
    if (_shared == null || !identical(_sharedApi, api)) {
      _shared = BankSmsHub(api);
      _sharedApi = api;
    }
    return _shared!;
  }

  final BankSmsService service;

  List<BankSms> open = const [];
  List<BankSms> done = const [];
  int openCount = 0;
  bool loading = false;
  String? error;
  Map<int, String> accounts = const {};

  /// Sends what the phone queued, then reads what is open.
  Future<void> sync() async {
    if (await BankSmsNative.isEnabled()) {
      await service.flush();
    }
    await refresh();
  }

  Future<void> refresh({bool withDone = false}) async {
    loading = true;
    notifyListeners();
    try {
      final o = await service.list();
      open = [for (final j in o.items) BankSms.fromJson(j)];
      openCount = o.open;
      if (withDone) {
        final d = await service.list(open: false);
        done = [for (final j in d.items) BankSms.fromJson(j)];
      }
      error = null;
    } on ApiException catch (e) {
      error = e.isConnectivityError ? 'اینترنت در دسترس نیست.' : e.message;
    } finally {
      loading = false;
      notifyListeners();
    }
  }

  Future<void> loadAccounts() async {
    try {
      final a = await service.accounts();
      accounts = {
        for (final x in rowList(a['accounts']))
          (x['id'] as num).toInt(): '${x['title']}',
      };
    } on ApiException {
      // The sheet falls back to «حساب وصل نشده».
    }
  }
}

void _say(BuildContext context, String message) =>
    ScaffoldMessenger.maybeOf(context)
        ?.showSnackBar(SnackBar(content: Text(message)));

/// The buttons, wired to the server. Shared by the inbox and the popup.
BankSmsActions bankSmsActions(BuildContext context, BankSmsHub hub) =>
    BankSmsActions(
      confirm: (sms) => _openConfirm(context, hub, sms),
      ignore: (sms) async {
        try {
          await hub.service.ignore(sms.id);
          if (context.mounted) _say(context, 'نادیده گرفته شد.');
        } on ApiException catch (e) {
          if (context.mounted) _say(context, e.message);
        }
        await hub.refresh(withDone: true);
      },
      pick: (sms, txId) async {
        try {
          await hub.service.link(sms.id, txId);
          if (context.mounted) _say(context, 'به ثبت قبلی وصل شد.');
        } on ApiException catch (e) {
          if (context.mounted) _say(context, e.message);
        }
        await hub.refresh(withDone: true);
      },
    );

Future<void> _openConfirm(
    BuildContext context, BankSmsHub hub, BankSms sms) async {
  if (sms.accountId == null && hub.accounts.isEmpty) await hub.loadAccounts();
  if (!context.mounted) return;

  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (sheet) => BankSmsConfirmSheet(
      sms: sms,
      accounts: hub.accounts,
      onIgnore: () async {
        Navigator.pop(sheet);
        try {
          await hub.service.ignore(sms.id);
        } on ApiException catch (e) {
          if (context.mounted) _say(context, e.message);
        }
        await hub.refresh(withDone: true);
      },
      onSubmit: (choice) async {
        Future<void> send({bool force = false}) => hub.service.confirm(sms.id,
            category: choice.category,
            accountId: choice.accountId,
            note: choice.note,
            force: force);
        try {
          await send();
        } on ApiException catch (e) {
          if (e.statusCode != 409 || !sheet.mounted) {
            if (context.mounted) _say(context, e.message);
            return;
          }
          // «همین مبلغ امروز یک بار ثبت شده» — booked only if they say so.
          final again = await showDialog<bool>(
            context: sheet,
            builder: (d) => AlertDialog(
              title: const Text('شاید تکراری'),
              content: Text(e.message),
              actions: [
                TextButton(
                    onPressed: () => Navigator.pop(d, false),
                    child: const Text('نه')),
                FilledButton(
                    style:
                        FilledButton.styleFrom(minimumSize: const Size(0, 40)),
                    onPressed: () => Navigator.pop(d, true),
                    child: const Text('بله، جداست')),
              ],
            ),
          );
          if (again != true) return;
          try {
            await send(force: true);
          } on ApiException catch (e2) {
            if (context.mounted) _say(context, e2.message);
            return;
          }
        }
        if (sheet.mounted) Navigator.pop(sheet);
        if (context.mounted) {
          _say(context, sms.isIncome ? 'درآمد ثبت شد.' : 'هزینه ثبت شد.');
        }
        await hub.refresh(withDone: true);
      },
    ),
  );
}

// -------------------------------------------------------------------- inbox

class BankSmsInboxPage extends StatefulWidget {
  const BankSmsInboxPage({super.key, required this.hub});

  final BankSmsHub hub;

  @override
  State<BankSmsInboxPage> createState() => _BankSmsInboxPageState();
}

class _BankSmsInboxPageState extends State<BankSmsInboxPage> {
  @override
  void initState() {
    super.initState();
    unawaited(widget.hub.refresh(withDone: true));
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: widget.hub,
        builder: (context, _) => BankSmsInboxScreen(
          open: widget.hub.open,
          done: widget.hub.done,
          loading: widget.hub.loading,
          error: widget.hub.error,
          actions: bankSmsActions(context, widget.hub),
          onRefresh: widget.hub.sync,
          onSettings: () => Navigator.push(
            context,
            MaterialPageRoute(
                builder: (_) => BankSmsSettingsPage(hub: widget.hub)),
          ),
        ),
      );
}

// --------------------------------------------------------------- settings

class BankSmsSettingsPage extends StatefulWidget {
  const BankSmsSettingsPage({super.key, required this.hub});

  final BankSmsHub hub;

  @override
  State<BankSmsSettingsPage> createState() => _BankSmsSettingsPageState();
}

class _BankSmsSettingsPageState extends State<BankSmsSettingsPage> {
  bool _enabled = false;
  bool _permitted = false;
  bool _showOnOpen = true;
  List<BankSmsMapping> _mappings = const [];
  List<String> _unmapped = const [];
  Map<int, String> _accounts = const {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final enabled = await BankSmsNative.isEnabled();
    final permitted = await BankSmsNative.hasPermission();
    final showOnOpen = await BankSmsService.showOnOpen();
    try {
      final a = await widget.hub.service.accounts();
      _mappings = [
        for (final m in rowList(a['mappings']))
          (
            id: (m['id'] as num).toInt(),
            last4: '${m['last4']}',
            accountName: m['account_name'] as String?,
          ),
      ];
      final unmapped = a['unmapped'];
      _unmapped =
          unmapped is List ? [for (final d in unmapped) '$d'] : const [];
      _accounts = {
        for (final x in rowList(a['accounts']))
          (x['id'] as num).toInt(): '${x['title']}',
      };
    } on ApiException catch (e) {
      if (mounted) _say(context, e.message);
    }
    if (!mounted) return;
    setState(() {
      _enabled = enabled;
      _permitted = permitted;
      _showOnOpen = showOnOpen;
    });
  }

  Future<void> _toggle(bool on) async {
    if (on) {
      final agreed = await Navigator.push<bool>(
        context,
        MaterialPageRoute(
          builder: (c) => BankSmsPermissionScreen(
            onAllow: () => Navigator.pop(c, true),
            onLater: () => Navigator.pop(c, false),
          ),
        ),
      );
      if (agreed != true) return;
      final granted = await BankSmsNative.requestPermission();
      if (!granted) {
        if (mounted) {
          _say(context, 'بدون اجازهٔ دریافت پیامک این بخش کار نمی‌کند.');
        }
        return;
      }
    }
    await BankSmsNative.setEnabled(on);
    await _load();
  }

  Future<void> _map(String? last4) async {
    final digits = TextEditingController(text: last4 ?? '');
    int? account;
    final ok = await showDialog<bool>(
      context: context,
      builder: (d) => StatefulBuilder(
        builder: (d, set) => AlertDialog(
          title: const Text('وصل کردن حساب'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            TextField(
              controller: digits,
              keyboardType: TextInputType.number,
              maxLength: 4,
              decoration:
                  const InputDecoration(labelText: 'چهار رقم آخر حساب یا کارت'),
            ),
            DropdownButtonFormField<int>(
              initialValue: account,
              decoration: const InputDecoration(labelText: 'حساب نانوایی'),
              items: [
                for (final a in _accounts.entries)
                  DropdownMenuItem(value: a.key, child: Text(a.value)),
              ],
              onChanged: (v) => set(() => account = v),
            ),
          ]),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(d, false),
                child: const Text('انصراف')),
            FilledButton(
                style: FilledButton.styleFrom(minimumSize: const Size(0, 40)),
                onPressed: () => Navigator.pop(d, true),
                child: const Text('ذخیره')),
          ],
        ),
      ),
    );
    if (ok != true || account == null) return;
    try {
      await widget.hub.service.saveAccount(digits.text.trim(), account!);
      if (mounted) _say(context, 'حساب وصل شد.');
    } on ApiException catch (e) {
      if (mounted) _say(context, e.message);
    }
    await _load();
    await widget.hub.refresh(withDone: true);
  }

  @override
  Widget build(BuildContext context) => BankSmsSettingsScreen(
        enabled: _enabled,
        permitted: _permitted,
        showOnOpen: _showOnOpen,
        mappings: _mappings,
        unmapped: _unmapped,
        accounts: _accounts,
        onEnabled: _toggle,
        onShowOnOpen: (v) async {
          await BankSmsService.setShowOnOpen(v);
          await _load();
        },
        onMap: _map,
        onUnmap: (m) async {
          try {
            await widget.hub.service.deleteAccount(m.id);
          } on ApiException catch (e) {
            if (mounted) _say(this.context, e.message);
          }
          await _load();
        },
      );
}

// ------------------------------------------------- shown when the app opens

/// Wraps the owner's home. On start and whenever the app comes back to the
/// front it sends the phone's queue and, if anything is waiting, opens the
/// list full screen — until «بعداً», which lasts for this session.
class BankSmsGate extends StatefulWidget {
  const BankSmsGate({super.key, required this.hub, required this.child});

  final BankSmsHub hub;
  final Widget child;

  @override
  State<BankSmsGate> createState() => _BankSmsGateState();
}

class _BankSmsGateState extends State<BankSmsGate> with WidgetsBindingObserver {
  bool _showing = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _check());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _check();
  }

  Future<void> _check() async {
    await widget.hub.sync();
    if (!mounted || _showing || BankSmsService.dismissedThisSession) return;
    if (widget.hub.open.isEmpty || !await BankSmsService.showOnOpen()) return;
    if (!mounted) return;

    _showing = true;
    await Navigator.of(context).push(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (c) => ListenableBuilder(
        listenable: widget.hub,
        builder: (c, _) {
          if (widget.hub.open.isEmpty) {
            WidgetsBinding.instance.addPostFrameCallback((_) {
              if (c.mounted && Navigator.of(c).canPop()) Navigator.of(c).pop();
            });
          }
          return BankSmsPendingPopup(
            items: widget.hub.open,
            actions: bankSmsActions(c, widget.hub),
            onLater: () {
              BankSmsService.dismissedThisSession = true;
              Navigator.of(c).pop();
            },
          );
        },
      ),
    ));
    _showing = false;
  }

  @override
  Widget build(BuildContext context) => widget.child;
}

/// The home card with its count; opens the inbox.
class BankSmsHomeEntry extends StatelessWidget {
  const BankSmsHomeEntry({super.key, required this.hub});

  final BankSmsHub hub;

  @override
  Widget build(BuildContext context) => ListenableBuilder(
        listenable: hub,
        builder: (context, _) => BankSmsHomeCard(
          pending: hub.openCount,
          onTap: () => Navigator.push(
            context,
            MaterialPageRoute(builder: (_) => BankSmsInboxPage(hub: hub)),
          ),
        ),
      );
}
