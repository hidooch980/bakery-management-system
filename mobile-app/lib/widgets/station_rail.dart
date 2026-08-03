import 'package:flutter/material.dart';

/// The shop's own sequence, drawn once at the top of the screen.
///
/// Dough is mixed, shaped, then sold, and a batch cannot skip a step. The
/// numbering is that process rather than decoration — it says where the
/// work actually is, and the figure under each station says how much is
/// sitting there right now.
class StationRail extends StatelessWidget {
  const StationRail({
    super.key,
    required this.stations,
    this.title = 'امروز',
    this.trailing,
  });

  final List<Station> stations;
  final String title;

  /// A short figure for the corner — the day's flour, usually.
  final String? trailing;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Text(
                title,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: scheme.onSurfaceVariant),
              ),
              const Spacer(),
              if (trailing != null)
                Text(
                  trailing!,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                        color: scheme.onSurfaceVariant,
                        fontWeight: FontWeight.w700,
                      ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          // The connecting line sits behind the markers so it reads as one
          // rail rather than three separate tiles.
          Stack(
            alignment: Alignment.topCenter,
            children: [
              Positioned(
                top: 15,
                left: 40,
                right: 40,
                child: Container(height: 2, color: scheme.outlineVariant),
              ),
              Row(
                children: [
                  for (var i = 0; i < stations.length; i++)
                    Expanded(
                      child: _StationMarker(
                        index: i + 1,
                        station: stations[i],
                      ),
                    ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// One step of the chain, and how much is waiting at it.
class Station {
  const Station({
    required this.label,
    required this.value,
    this.state = StationState.idle,
  });

  final String label;
  final String value;
  final StationState state;
}

enum StationState {
  /// Nothing waiting here.
  idle,

  /// Work is sitting here now — the one worth looking at.
  active,

  /// Already through this step today.
  done,
}

class _StationMarker extends StatelessWidget {
  const _StationMarker({required this.index, required this.station});

  final int index;
  final Station station;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final isDark = Theme.of(context).brightness == Brightness.dark;

    final (Color fill, Color border, Color label) = switch (station.state) {
      StationState.active => (scheme.primary, scheme.primary, scheme.onPrimary),
      StationState.done => (
          isDark ? const Color(0xFF35C793) : const Color(0xFF0B7A54),
          isDark ? const Color(0xFF35C793) : const Color(0xFF0B7A54),
          Colors.white,
        ),
      StationState.idle => (
          scheme.surface,
          scheme.outlineVariant,
          scheme.onSurfaceVariant,
        ),
    };

    return Column(
      children: [
        Container(
          width: 30,
          height: 30,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: fill,
            shape: BoxShape.circle,
            border: Border.all(color: border, width: 2),
          ),
          child: Text(
            '$index',
            style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w800,
              color: label,
            ),
          ),
        ),
        const SizedBox(height: 7),
        Text(
          station.value,
          style: Theme.of(context)
              .textTheme
              .titleSmall
              ?.copyWith(fontWeight: FontWeight.w800),
        ),
        Text(
          station.label,
          style: Theme.of(context)
              .textTheme
              .bodySmall
              ?.copyWith(color: scheme.onSurfaceVariant),
        ),
      ],
    );
  }
}
