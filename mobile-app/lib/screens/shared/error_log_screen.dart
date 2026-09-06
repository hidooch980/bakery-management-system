import 'package:flutter/material.dart';

import '../../services/error_log.dart';

/// What the app has to say for itself.
///
/// Opened when something looked wrong and somebody wants to say what. It
/// exists because the answer to «چه شد؟» used to be nothing at all: a grey
/// rectangle on the screen, a message in a console no one on a shop floor
/// can open, and a report that could only ever be «کار نکرد».
///
/// Nothing here is sent anywhere. It is read out, or photographed, by the
/// person holding the phone.
class ErrorLogScreen extends StatelessWidget {
  const ErrorLogScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(
        title: const Text('گزارش خطاها'),
        actions: [
          ValueListenableBuilder<List<LoggedError>>(
            valueListenable: ErrorLog.entries,
            builder: (context, entries, _) => entries.isEmpty
                ? const SizedBox.shrink()
                : IconButton(
                    icon: const Icon(Icons.delete_outline_rounded),
                    tooltip: 'پاک کردن',
                    onPressed: ErrorLog.clear,
                  ),
          ),
        ],
      ),
      body: ValueListenableBuilder<List<LoggedError>>(
        valueListenable: ErrorLog.entries,
        builder: (context, entries, _) {
          if (entries.isEmpty) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Text(
                  'از وقتی برنامه باز شده، خطایی ثبت نشده است.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: scheme.onSurfaceVariant),
                ),
              ),
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(12),
            itemCount: entries.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final entry = entries[i];

              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        [
                          '${entry.at.hour.toString().padLeft(2, '0')}'
                              ':${entry.at.minute.toString().padLeft(2, '0')}',
                          if (entry.where != null) entry.where!,
                        ].join('  ·  '),
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                      ),
                      const SizedBox(height: 6),

                      // Left as the machine wrote it, in the language it
                      // wrote it in. Translating would lose the file and
                      // the type, which are the two things worth having.
                      SelectableText(
                        entry.message,
                        textDirection: TextDirection.ltr,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color: scheme.onSurface,
                            ),
                      ),
                    ],
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
