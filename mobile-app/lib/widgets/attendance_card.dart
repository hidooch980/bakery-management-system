import 'package:flutter/material.dart';

import '../models/entries.dart';
import '../utils/formatters.dart';

import '../services/api_client.dart';
import '../services/bakery_api.dart';
import 'common.dart';
import '../theme/app_theme.dart';

/// The attendance check-in control every staff role sees on their home screen.
/// The recorded time is what the admin reads in the attendance report.
class AttendanceCard extends StatefulWidget {
  const AttendanceCard({
    super.key,
    required this.api,
    this.canRecordForOthers = false,
  });

  final BakeryApi api;

  /// Shows the control for ticking in the rest of the floor. Off by default
  /// so a role without the permission never sees a button the server would
  /// refuse.
  final bool canRecordForOthers;

  @override
  State<AttendanceCard> createState() => _AttendanceCardState();
}

class _AttendanceCardState extends State<AttendanceCard> {
  bool _loading = true;

  /// Today's state could not be read. The card still draws — a seller who
  /// cannot see whether they ticked in must at least be able to try.
  bool _failed = false;
  bool _submitting = false;
  bool _checkedIn = false;
  DateTime? _checkedInAt;

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    try {
      final status = await widget.api.attendanceToday();
      if (!mounted) return;
      setState(() {
        _checkedIn = status.checkedIn;
        _checkedInAt = status.at;
        _loading = false;
        _failed = false;
      });
    } catch (_) {
      // Every failure, not only ApiException. This caught ApiException
      // alone, so a dropped connection or a timeout escaped, _loading was
      // never cleared, and the whole card stayed a spinner — which also
      // hid the button for marking everyone else in, since that sits
      // inside the loaded branch. The seller saw an empty card until
      // their own check-in warmed the cache.
      if (!mounted) return;
      setState(() {
        _loading = false;
        _failed = true;
      });
    }
  }

  Future<void> _checkIn() async {
    setState(() => _submitting = true);

    try {
      final result = await widget.api.checkIn();
      if (!mounted) return;
      setState(() {
        _checkedIn = true;
        _checkedInAt = result.record?.checkedInAt ?? DateTime.now();
      });
      showMessage(
        context,
        result.queued
            ? 'اینترنت وصل نیست؛ حضور شما ذخیره شد و با اتصال بعدی ثبت می‌شود.'
            : 'تیک حضور شما ثبت شد.',
      );
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
      if (e.statusCode == 409) _refresh();
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    const done = AppColors.moneyIn;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: AnimatedSize(
          duration: const Duration(milliseconds: 250),
          // The two halves are independent: whether this person ticked in
          // has nothing to do with whether the others have, and nesting
          // the roster inside the loaded branch meant one failed request
          // took away a button about somebody else entirely.
          child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (_loading)
                      const SizedBox(
                        height: 76,
                        child: Center(child: CircularProgressIndicator()),
                      )
                    else
                      Row(
                  children: [
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 350),
                      width: 56,
                      height: 56,
                      decoration: BoxDecoration(
                        color: (_checkedIn ? done : scheme.primary)
                            .withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(Corner.control),
                      ),
                      child: Icon(
                        _checkedIn
                            ? Icons.how_to_reg_rounded
                            : Icons.fingerprint_rounded,
                        color: _checkedIn ? done : scheme.primary,
                        size: IconSize.large,
                      ),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _failed
                                ? 'وضعیت امروز خوانده نشد'
                                : (_checkedIn ? 'حضور ثبت شده' : 'تیک حضور امروز'),
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            _failed
                                ? 'اینترنت وصل نشد — دوباره بزنید'
                                : (_checkedIn && _checkedInAt != null
                                    ? 'ساعت ${JalaliFormat.time(_checkedInAt!)}'
                                    : 'برای ثبت ساعت ورود ضربه بزنید'),
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: scheme.onSurfaceVariant),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 12),
                    if (_failed)
                      IconButton(
                        onPressed: () {
                          setState(() {
                            _loading = true;
                            _failed = false;
                          });
                          _refresh();
                        },
                        tooltip: 'دوباره',
                        icon: const Icon(Icons.refresh_rounded),
                      )
                    else if (_checkedIn)
                      const Icon(Icons.check_circle_rounded,
                          color: done, size: IconSize.large)
                    else
                      FilledButton(
                        onPressed: _submitting ? null : _checkIn,
                        style: FilledButton.styleFrom(
                          minimumSize: const Size(104, 48),
                        ),
                        child: _submitting
                            ? SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2, color: Theme.of(context).colorScheme.onPrimary),
                              )
                            : const Text('ثبت'),
                      ),
                      ],
                    ),
                    if (widget.canRecordForOthers) ...[
                      const Divider(height: 22),
                      // The bakers are at the oven with flour on their
                      // hands; whoever holds a phone marks them in.
                      TextButton.icon(
                        onPressed: () => showModalBottomSheet<void>(
                          context: context,
                          isScrollControlled: true,
                          builder: (_) => _RosterSheet(api: widget.api),
                        ),
                        icon: const Icon(Icons.groups_rounded, size: IconSize.row),
                        label: const Text('ثبت حضور بقیه کارکنان'),
                      ),
                    ],
                  ],
                ),
        ),
      ),
    );
  }
}

/// Ticks in the staff who are not carrying a phone.
///
/// Opened from the attendance card by whoever holds the permission. Each
/// row is one tap, and the row settles into "in" without closing the sheet
/// so the whole floor can be marked in one pass.
class _RosterSheet extends StatefulWidget {
  const _RosterSheet({required this.api});

  final BakeryApi api;

  @override
  State<_RosterSheet> createState() => _RosterSheetState();
}

class _RosterSheetState extends State<_RosterSheet> {
  List<StaffAttendance>? _staff;
  String? _error;
  final Set<int> _busy = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final staff = await widget.api.attendanceRoster();
      if (!mounted) return;
      setState(() {
        _staff = staff;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.message);
    } catch (_) {
      // Anything that is not the server talking is the connection, and
      // catching only ApiException left this sheet spinning for ever with
      // nothing to say and no way to try again.
      if (!mounted) return;
      setState(() => _error = 'اینترنت وصل نشد. دوباره تلاش کنید.');
    }
  }

  Future<void> _tickIn(StaffAttendance person) async {
    setState(() => _busy.add(person.id));

    try {
      final queued = await widget.api.checkInFor(person.id);
      if (!mounted) return;

      // Replaced rather than reloaded: a reload would lose the other rows
      // mid-pass and the person doing this is standing on a busy floor.
      setState(() {
        _staff = [
          for (final s in _staff ?? const <StaffAttendance>[])
            if (s.id == person.id)
              StaffAttendance(
                id: s.id,
                name: s.name,
                role: s.role,
                checkedIn: true,
                checkedInAt: s.checkedInAt,
                recordedByAnother: true,
              )
            else
              s,
        ];
      });

      if (queued) {
        showMessage(
          context,
          'اینترنت وصل نیست؛ حضور ${person.name} ذخیره شد و با اتصال بعدی ثبت می‌شود.',
        );
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
      // 409 means they got there first; the roster is what is stale.
      if (e.statusCode == 409) _load();
    } finally {
      if (mounted) setState(() => _busy.remove(person.id));
    }
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    const done = AppColors.moneyIn;
    final staff = _staff;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'ثبت حضور کارکنان',
              style: Theme.of(context)
                  .textTheme
                  .titleMedium
                  ?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(
              'برای هر نفر که سر کار آمده ضربه بزنید.',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: scheme.onSurfaceVariant),
            ),
            const SizedBox(height: 12),
            if (_error != null)
              Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(_error!, style: TextStyle(color: scheme.error)),
                  const SizedBox(height: 8),
                  TextButton.icon(
                    onPressed: () {
                      setState(() => _error = null);
                      _load();
                    },
                    icon: const Icon(Icons.refresh_rounded, size: IconSize.row),
                    label: const Text('دوباره'),
                  ),
                ],
              )
            else if (staff == null)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 28),
                child: Center(child: CircularProgressIndicator()),
              )
            else if (staff.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 20),
                child: Text('کارمند فعال دیگری ثبت نشده است.'),
              )
            else
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: staff.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (_, i) {
                    final person = staff[i];
                    final working = _busy.contains(person.id);

                    return ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(person.name),
                      subtitle: person.checkedIn
                          ? Text(
                              person.checkedInAt != null
                                  ? 'ساعت ${person.checkedInAt}'
                                  : 'ثبت شده',
                              style: const TextStyle(color: done),
                            )
                          : Text(
                              person.role ?? '',
                              style: TextStyle(color: scheme.onSurfaceVariant),
                            ),
                      trailing: person.checkedIn
                          ? const Icon(Icons.check_circle_rounded,
                              color: done, size: IconSize.heading)
                          : FilledButton(
                              onPressed:
                                  working ? null : () => _tickIn(person),
                              child: working
                                  ? SizedBox(
                                      width: 18,
                                      height: 18,
                                      child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: Theme.of(context).colorScheme.onPrimary),
                                    )
                                  : const Text('ثبت'),
                            ),
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }
}
