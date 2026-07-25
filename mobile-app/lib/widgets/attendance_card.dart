import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../services/api_client.dart';
import '../services/bakery_api.dart';
import 'common.dart';

/// The attendance check-in control every staff role sees on their home screen.
/// The recorded time is what the admin reads in the attendance report.
class AttendanceCard extends StatefulWidget {
  const AttendanceCard({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AttendanceCard> createState() => _AttendanceCardState();
}

class _AttendanceCardState extends State<AttendanceCard> {
  bool _loading = true;
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
      });
    } on ApiException {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  Future<void> _checkIn() async {
    setState(() => _submitting = true);

    try {
      final record = await widget.api.checkIn();
      if (!mounted) return;
      setState(() {
        _checkedIn = true;
        _checkedInAt = record.checkedInAt;
      });
      showMessage(context, 'تیک حضور شما ثبت شد.');
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
    const done = Color(0xFF2E9E6B);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: AnimatedSize(
          duration: const Duration(milliseconds: 250),
          child: _loading
              ? const SizedBox(
                  height: 76,
                  child: Center(child: CircularProgressIndicator()),
                )
              : Row(
                  children: [
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 350),
                      width: 56,
                      height: 56,
                      decoration: BoxDecoration(
                        color: (_checkedIn ? done : scheme.primary)
                            .withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Icon(
                        _checkedIn
                            ? Icons.how_to_reg_rounded
                            : Icons.fingerprint_rounded,
                        color: _checkedIn ? done : scheme.primary,
                        size: 30,
                      ),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _checkedIn ? 'حضور ثبت شده' : 'تیک حضور امروز',
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            _checkedIn && _checkedInAt != null
                                ? 'ساعت ${DateFormat('HH:mm').format(_checkedInAt!)}'
                                : 'برای ثبت ساعت ورود ضربه بزنید',
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(color: scheme.onSurfaceVariant),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 12),
                    if (_checkedIn)
                      const Icon(Icons.check_circle_rounded,
                          color: done, size: 32)
                    else
                      FilledButton(
                        onPressed: _submitting ? null : _checkIn,
                        style: FilledButton.styleFrom(
                          minimumSize: const Size(104, 48),
                        ),
                        child: _submitting
                            ? const SizedBox(
                                width: 20,
                                height: 20,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2, color: Colors.white),
                              )
                            : const Text('ثبت'),
                      ),
                  ],
                ),
        ),
      ),
    );
  }
}
