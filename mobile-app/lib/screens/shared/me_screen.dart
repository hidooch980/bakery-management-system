import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../providers/auth_provider.dart';
import '../../services/bakery_api.dart';
import '../../widgets/attendance_card.dart';
import '../../widgets/lateness_card.dart';
import '../../widgets/pay_card.dart';

/// Everything about the person rather than the work: attendance, what
/// their pay stands at, and what they have asked for.
///
/// The production roles ask one question per screen now, which leaves
/// nowhere on that screen for a card about wages — and wages are not a
/// distraction, they are the reason most people open an app about their
/// job at all. So they live one tap away, behind their own name in the
/// bar, where they can be looked at deliberately instead of scrolled past
/// on the way to entering a number.
class MeScreen extends StatelessWidget {
  const MeScreen({super.key, required this.api});

  final BakeryApi api;

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().user;

    return Scaffold(
      appBar: AppBar(title: Text(user?.name ?? 'حساب من')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 28),
        children: [
          AttendanceCard(api: api),
          const SizedBox(height: 14),

          // Between attendance and pay, because that is the order the two
          // are connected in: being late is an attendance fact that turns
          // into a pay one. It renders nothing at all on a clean month
          // past the first line, so it costs a reader nothing.
          LatenessCard(api: api),
          const SizedBox(height: 14),
          PayCard(api: api),
        ],
      ),
    );
  }
}
