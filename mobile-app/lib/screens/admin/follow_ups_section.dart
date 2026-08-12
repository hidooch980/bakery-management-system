import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/bakery_api.dart';
import '../../widgets/common.dart';
import 'admin_home_screen.dart';

/// Today's call list: the follow-ups that have come due.
///
/// A promise made on the phone is worth nothing if nobody is reminded of
/// it, so the ones that have come round stay here until they are dealt
/// with, each with what the customer owes — usually the reason for the call.
class FollowUpsSection extends StatefulWidget {
  const FollowUpsSection({super.key, required this.api});

  final BakeryApi api;

  @override
  State<FollowUpsSection> createState() => _FollowUpsSectionState();
}

class _FollowUpsSectionState extends State<FollowUpsSection> {
  late Future<List<Map<String, dynamic>>> _followUps;

  @override
  void initState() {
    super.initState();
    _followUps = widget.api.dueFollowUps();
  }

  void _reload() => setState(() => _followUps = widget.api.dueFollowUps());

  Future<void> _complete(Map<String, dynamic> followUp) async {
    try {
      final queued = await widget.api.completeFollowUp(followUp['id'] as int);
      if (!mounted) return;
      showMessage(
        context,
        queued
            ? 'ذخیره شد و با وصل شدن اینترنت ارسال می‌شود.'
            : 'پیگیری انجام شد.',
      );
      _reload();
    } on ApiException catch (e) {
      if (!mounted) return;
      showMessage(context, e.message, isError: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _followUps,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: CircularProgressIndicator()),
          );
        }

        if (snapshot.hasError) {
          return ErrorBox(message: '${snapshot.error}', onRetry: _reload);
        }

        final followUps = snapshot.data!;

        if (followUps.isEmpty) {
          return const AdminSection(
            title: 'پیگیری‌های امروز',
            icon: Icons.phone_in_talk_rounded,
            children: [
              AdminRow(label: 'وضعیت', value: 'پیگیری سررسیدشده‌ای نیست'),
            ],
          );
        }

        return AdminSection(
          title: 'پیگیری‌های امروز',
          icon: Icons.phone_in_talk_rounded,
          trailing: Text(
            '${followUps.length} مورد',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: const Color(0xFFE8952D),
                ),
          ),
          children: [
            for (final followUp in followUps)
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
                child: _FollowUpTile(
                  followUp: followUp,
                  onComplete: () => _complete(followUp),
                ),
              ),
          ],
        );
      },
    );
  }
}

class _FollowUpTile extends StatelessWidget {
  const _FollowUpTile({required this.followUp, required this.onComplete});

  final Map<String, dynamic> followUp;
  final VoidCallback onComplete;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final overdue = followUp['is_overdue'] == true;
    final accent = overdue ? const Color(0xFFD1495B) : const Color(0xFFE8952D);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(
              overdue ? Icons.warning_amber_rounded : Icons.schedule_rounded,
              size: 16,
              color: accent,
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                '${followUp['customer_name']}',
                style: Theme.of(context)
                    .textTheme
                    .bodyMedium
                    ?.copyWith(fontWeight: FontWeight.w800),
              ),
            ),
            Text(
              '${followUp['outstanding_formatted']}',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: const Color(0xFFD1495B),
                  ),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          '${followUp['type_label']}  •  ${followUp['summary']}',
          style: Theme.of(context).textTheme.bodySmall,
        ),
        Row(
          children: [
            Expanded(
              child: Text(
                'موعد ${followUp['follow_up_display']}'
                '${overdue ? ' — عقب‌افتاده' : ''}'
                '${followUp['customer_phone'] != null ? '  •  ${followUp['customer_phone']}' : ''}',
                style: Theme.of(context).textTheme.bodySmall?.copyWith(
                      color: overdue ? accent : scheme.onSurfaceVariant,
                    ),
              ),
            ),
            TextButton(
              onPressed: onComplete,
              child: const Text('انجام شد'),
            ),
          ],
        ),
        const Divider(height: 18),
      ],
    );
  }
}
