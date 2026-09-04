import 'package:flutter/material.dart';

import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../utils/formatters.dart';

/// Says so when what is on screen came from the phone rather than the shop.
///
/// The cache has served offline reads since it was written, and nothing
/// ever said it was doing so: `ApiClient.servedFrom` existed and no screen
/// called it. So a manager opening the bank balance in a lift saw last
/// night's figure with no mark on it, indistinguishable from this
/// morning's. Showing the saved copy is right — an empty screen helps
/// nobody — but showing it silently is how a stale number gets acted on.
///
/// Renders nothing while everything came from the server, which is the
/// common case.
class SavedCopyBanner extends StatelessWidget {
  const SavedCopyBanner({super.key, required this.client});

  final ApiClient client;

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<DateTime?>(
      valueListenable: client.savedCopyAt,
      builder: (context, at, _) {
        if (at == null) return const SizedBox.shrink();

        return Card(
          color: AppColors.attention.withValues(alpha: 0.08),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                Icon(Icons.history_rounded, color: AppColors.attention),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    // The time, not «قدیمی». Whether a figure from two
                    // hours ago is usable depends on which figure it is,
                    // and the person reading it is the one who knows.
                    'این ارقام از نسخهٔ ذخیره‌شده است — ${JalaliFormat.dateTime(at)}',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
