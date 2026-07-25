import 'package:flutter/material.dart';

import '../../services/bakery_api.dart';
import '../../utils/formatters.dart';
import '../../widgets/common.dart';

/// Who checked in today, and at what time.
class AdminStaffTab extends StatefulWidget {
  const AdminStaffTab({super.key, required this.api});

  final BakeryApi api;

  @override
  State<AdminStaffTab> createState() => _AdminStaffTabState();
}

class _AdminStaffTabState extends State<AdminStaffTab> {
  late Future<List<Map<String, dynamic>>> _attendance;

  @override
  void initState() {
    super.initState();
    _attendance = widget.api.adminAttendanceToday();
  }

  void _reload() =>
      setState(() => _attendance = widget.api.adminAttendanceToday());

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return RefreshIndicator(
      onRefresh: () async => _reload(),
      child: FutureBuilder<List<Map<String, dynamic>>>(
        future: _attendance,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return ListView(
              padding: const EdgeInsets.all(20),
              children: [ErrorBox(message: '${snapshot.error}', onRetry: _reload)],
            );
          }

          final records = snapshot.data ?? const <Map<String, dynamic>>[];

          if (records.isEmpty) {
            return ListView(
              children: const [
                SizedBox(height: 60),
                EmptyState(
                  icon: Icons.how_to_reg_outlined,
                  title: 'هنوز کسی تیک حضور نزده',
                  subtitle: 'ساعت ورود کارکنان اینجا نمایش داده می‌شود.',
                ),
              ],
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
            itemCount: records.length,
            separatorBuilder: (_, __) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final record = records[index];
              final user = record['user'] as Map<String, dynamic>?;
              final checkedInAt = DateTime.tryParse('${record['checked_in_at']}');

              return Card(
                child: ListTile(
                  contentPadding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  leading: CircleAvatar(
                    backgroundColor: scheme.primary.withValues(alpha: 0.14),
                    child: Icon(Icons.person_rounded, color: scheme.primary),
                  ),
                  title: Text(
                    '${user?['name'] ?? '—'}',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  subtitle: Text(JalaliFormat.date(checkedInAt)),
                  trailing: Chip(
                    label: Text(JalaliFormat.time(checkedInAt)),
                    avatar: const Icon(Icons.schedule_rounded, size: 16),
                    backgroundColor:
                        const Color(0xFF2E9E6B).withValues(alpha: 0.15),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
