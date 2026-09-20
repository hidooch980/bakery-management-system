import 'package:flutter/material.dart';

import '../models/bakery.dart';
import '../models/entries.dart';
import '../theme/app_theme.dart';
import '../utils/formatters.dart';

/// The seller's «یک کار»: one batch, one question, one button.
///
/// The seller's job is not «how many did you sell» — that was my first
/// drawing of this screen and it was wrong. What the shop needs from him is
/// **how one batch divided**: so many cash, so many on the card, so many to
/// a school, and whatever is left over lands on his own account as a
/// shortfall. A single number cannot say that.
///
/// But it is very nearly always all cash, and the old sheet knew that — it
/// pre-filled the cash field with the whole batch and waited for the seller
/// to scroll past five more fields to agree with it. So the question is not
/// «how many», it is **«همه‌اش نقدی بود؟»**: the assumption stated out loud,
/// with one button to confirm it and one to say otherwise.
///
/// The exception path is the old sheet, unchanged. A day that really did
/// divide six ways is a day worth six fields, and rewriting that form to
/// look tidier would have been the risky half of this change with none of
/// the benefit.
///
/// Only shown when exactly one batch is waiting. Choosing between several
/// is a real choice and gets the list — the same rule the chane maker's
/// screen follows, where the second question disappears when only one
/// batch is there to pick.
class SellerAsk extends StatelessWidget {
  const SellerAsk({
    super.key,
    required this.chane,
    required this.bakery,
    required this.onSplit,
  });

  final ChaneEntry chane;
  final Bakery? bakery;

  /// Confirming the assumption: the whole batch, cash.

  /// Saying otherwise, which opens the full sheet.
  final VoidCallback onSplit;


  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.color;
    final price = bakery?.breadPrice ?? 0;
    final unit = bakery?.currency ?? Currency.toman;
    final total = chane.chaneCount * price;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 12),

        // Was «همه‌اش نقدی بود؟» — a question whose easy answer no longer
        // exists. The screen states what there is to account for instead.
        Text(
          'این چانه کجا رفت؟',
          textAlign: TextAlign.center,
          style: theme.textTheme.headlineSmall?.copyWith(
            fontWeight: FontWeight.w600,
            color: muted,
          ),
        ),

        const SizedBox(height: 10),

        // The count at the size of a fist, the way the dough screen asks
        // for sacks. A person with floury hands should read this without
        // leaning in.
        Text(
          '${chane.chaneCount}',
          textAlign: TextAlign.center,
          style: theme.textTheme.displayLarge?.copyWith(
            fontWeight: FontWeight.w900,
            height: 1,
          ),
        ),

        const SizedBox(height: 6),

        Text(
          price > 0
              ? 'چانه  •  ${MoneyFormat.format(total, currency: unit)}'
              : 'چانه',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodyMedium?.copyWith(color: muted),
        ),

        const SizedBox(height: 26),

        // Was a yellow «بله — همه نقدی» that posted the whole batch as
        // cash in one tap, with «نه، فرق داشت» under it. Cash came off
        // the sale sheet at the owner's word on 1405/06/29, and a
        // one-tap shortcut for it would have been the largest cash path
        // of all — left standing, it would have made the removal
        // cosmetic.
        //
        // So the question is gone and the sheet is the answer: the
        // seller says where the bread went.
        FilledButton(
          onPressed: onSplit,
          style: FilledButton.styleFrom(
            padding: const EdgeInsets.symmetric(vertical: 17),
            backgroundColor: AppColors.signalFor(theme.brightness),
            foregroundColor: AppColors.onSignal,
            textStyle: theme.textTheme.titleMedium?.copyWith(
              fontWeight: FontWeight.w800,
            ),
          ),
          child: const Text('ثبت فروش'),
        ),

        const SizedBox(height: 8),

        Text(
          'کارتخوان، مدارس، منزل یا خیرات — و هرچه نام نبرید، کسری شماست',
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall?.copyWith(color: muted),
        ),
      ],
    );
  }
}
