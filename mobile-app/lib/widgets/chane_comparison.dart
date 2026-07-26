import 'package:flutter/material.dart';

import '../models/chane_board.dart';

/// Side-by-side comparison of today's nanino and normal chane output.
/// Shown to the chane gir and the seller so they can see the split at a glance.
class ChaneComparison extends StatelessWidget {
  const ChaneComparison({super.key, required this.board});

  final ChaneBoard board;

  static const _normalColor = Color(0xFFE8952D);
  static const _naninoColor = Color(0xFF3B82C4);

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(Icons.compare_arrows_rounded, color: scheme.primary),
                const SizedBox(width: 10),
                Text(
                  'مقایسه تولید امروز',
                  style: Theme.of(context)
                      .textTheme
                      .titleMedium
                      ?.copyWith(fontWeight: FontWeight.w700),
                ),
                const Spacer(),
                Text(
                  board.dateDisplay,
                  style: Theme.of(context)
                      .textTheme
                      .bodySmall
                      ?.copyWith(color: scheme.onSurfaceVariant),
                ),
              ],
            ),
            const SizedBox(height: 18),

            if (board.totalCount == 0)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 20),
                child: Text(
                  'امروز هنوز چانه‌ای ثبت نشده است.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context)
                      .textTheme
                      .bodyMedium
                      ?.copyWith(color: scheme.onSurfaceVariant),
                ),
              )
            else ...[
              Row(
                children: [
                  Expanded(
                    child: _Metric(
                      label: 'چانه عادی (ملاک)',
                      count: board.normalCount,
                      weightKg: board.normalWeightKg,
                      color: _normalColor,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: _Metric(
                      label: 'چانه نانینو (نمایشی)',
                      count: board.naninoCount,
                      weightKg: board.naninoWeightKg,
                      color: _naninoColor,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 18),
              _ShareBar(
                normalShare: board.normalShare,
                naninoShare: board.naninoShare,
                normalColor: _normalColor,
                naninoColor: _naninoColor,
              ),
              const SizedBox(height: 10),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  _Legend(
                    color: _normalColor,
                    text: '${(board.normalShare * 100).toStringAsFixed(0)}٪ عادی',
                  ),
                  _Legend(
                    color: _naninoColor,
                    text: '${(board.naninoShare * 100).toStringAsFixed(0)}٪ نانینو',
                  ),
                ],
              ),
              const SizedBox(height: 14),
              _Verdict(board: board),

              if (board.naninoAnnouncement != null) ...[
                const SizedBox(height: 10),
                _NaninoEquivalentBanner(board: board),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

/// A what-if comparison: how many nanino loaves today's normal chane would
/// be, had it been shaped as nanino instead. Not a count of anything
/// actually baked — shown so both the seller and the admin see the same
/// figure everywhere it appears.
class _NaninoEquivalentBanner extends StatelessWidget {
  const _NaninoEquivalentBanner({required this.board});

  final ChaneBoard board;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: ChaneComparison._naninoColor.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: ChaneComparison._naninoColor.withValues(alpha: 0.3),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Icon(Icons.swap_horiz_rounded,
              size: 18, color: ChaneComparison._naninoColor),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              board.naninoAnnouncement!,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: scheme.onSurface,
                    fontWeight: FontWeight.w600,
                  ),
            ),
          ),
        ],
      ),
    );
  }
}

/// States the comparison in words, so the two numbers above do not have to
/// be read against each other.
class _Verdict extends StatelessWidget {
  const _Verdict({required this.board});

  final ChaneBoard board;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final leader = board.leader;

    final colour = leader == null
        ? scheme.onSurfaceVariant
        : (leader == ChaneSystem.normal
            ? ChaneComparison._normalColor
            : ChaneComparison._naninoColor);

    final ratio = board.normalToNaninoRatio;

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.45),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            leader == null
                ? 'تولید دو سیستم برابر است.'
                : '${leader.label} ${board.countDifference} عدد بیشتر '
                    '(${board.weightDifferenceKg.toStringAsFixed(1)} کیلوگرم اختلاف)',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: colour,
                ),
          ),
          const SizedBox(height: 4),
          Text(
            ratio == null
                ? 'مجموع ${board.totalCount} چانه'
                : 'عادی ${ratio.toStringAsFixed(1)} برابر نانینو   •   '
                    'مجموع ${board.totalCount} چانه',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
          ),
        ],
      ),
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.label,
    required this.count,
    required this.weightKg,
    required this.color,
  });

  final String label;
  final int count;
  final double weightKg;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withValues(alpha: 0.28)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            label,
            style: Theme.of(context)
                .textTheme
                .bodySmall
                ?.copyWith(color: color, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Text(
            '$count',
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: color,
                ),
          ),
          const SizedBox(height: 2),
          Text(
            '${weightKg.toStringAsFixed(1)} کیلوگرم',
            style: Theme.of(context).textTheme.bodySmall?.copyWith(
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
          ),
        ],
      ),
    );
  }
}

/// A single bar whose two segments are proportional to each system's share.
class _ShareBar extends StatelessWidget {
  const _ShareBar({
    required this.normalShare,
    required this.naninoShare,
    required this.normalColor,
    required this.naninoColor,
  });

  final double normalShare;
  final double naninoShare;
  final Color normalColor;
  final Color naninoColor;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(8),
      child: SizedBox(
        height: 14,
        child: Row(
          children: [
            Expanded(
              // flex needs whole numbers, so scale the fractions up.
              flex: (normalShare * 1000).round().clamp(0, 1000),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 400),
                color: normalColor,
              ),
            ),
            Expanded(
              flex: (naninoShare * 1000).round().clamp(0, 1000),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 400),
                color: naninoColor,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend({required this.color, required this.text});

  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(color: color, shape: BoxShape.circle),
        ),
        const SizedBox(width: 6),
        Text(text, style: Theme.of(context).textTheme.bodySmall),
      ],
    );
  }
}
