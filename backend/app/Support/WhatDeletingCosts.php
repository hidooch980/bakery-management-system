<?php

namespace App\Support;

use App\Models\ChaneEntry;
use App\Models\DoughEntry;
use App\Models\Sale;

/**
 * What goes with a batch when somebody deletes it.
 *
 * Deleting a dough entry is how a mistyped sack count gets corrected, and
 * it is the right way: the chane rows go with it and the flour comes back
 * to the store, all on the model path so nothing is left half-reversed.
 *
 * What nobody is told is that the day's **sales** go too, and their bank
 * postings with them. On 1405/06/06 a batch was deleted to fix it and a
 * card sale vanished by cascade — 7,290,000 sat in the account with no
 * record to explain it, and somebody had to type it back in by hand a
 * fortnight later. The money is real and stays in the bank; only the
 * shop's knowledge of it is destroyed.
 *
 * So the figure is put in front of whoever is about to click. Not a
 * refusal: correcting a genuinely wrong batch is a thing people must be
 * able to do, and the sales are re-entered afterwards. It is the sentence
 * that makes re-entering them something the owner knows to do.
 */
class WhatDeletingCosts
{
    /**
     * The sales that would go with this record, and what they came to.
     *
     * `banked` is counted apart because only those are money the bank
     * already holds. A cash sale's notes are in the seller's hand until
     * they hand over, so deleting it destroys a record of a debt, not of
     * a deposit — a worse thing to say wrongly than not to say at all.
     *
     * @return array{count: int, amount: float, banked: int, banked_amount: float}
     */
    public static function of(DoughEntry|ChaneEntry $record): array
    {
        $sales = fn () => $record instanceof ChaneEntry
            ? Sale::where('chane_entry_id', $record->getKey())
            : Sale::whereIn(
                'chane_entry_id',
                $record->chaneEntries()->select('id'),
            );

        // Asked as «does it name an account», not «is it a card sale».
        // A sale posts only where `bank_account_id` is set, so that column
        // is the money actually recorded somewhere — and a card sale that
        // never named an account has nothing in a bank to be left behind.
        $banked = $sales()->whereNotNull('bank_account_id');

        return [
            'count' => (int) $sales()->count(),
            'amount' => round((float) $sales()->sum('amount'), 2),
            'banked' => (int) $banked->count(),
            'banked_amount' => round((float) $banked->sum('amount'), 2),
        ];
    }

    /**
     * The warning, or nothing at all.
     *
     * A batch nobody has sold from yet is the ordinary case — the owner
     * spotting a mistyped sack count minutes after entering it — and a
     * warning there would be noise that teaches people to click through
     * the one that matters.
     */
    public static function warningFor(DoughEntry|ChaneEntry $record): ?string
    {
        $counted = self::of($record);

        if ($counted['count'] === 0) {
            return null;
        }

        $warning = sprintf(
            'با این حذف، %s فروش به مبلغ %s هم پاک می‌شود.',
            number_format($counted['count']),
            Money::format($counted['amount']),
        );

        // Said only when it is true. The 06/06 loss was a card sale: its
        // money had already reached the bank and stayed there, with
        // nothing left in the books to explain it. Cash in a seller's
        // pocket is a different problem and saying this about it would
        // be wrong.
        if ($counted['banked'] > 0) {
            $warning .= sprintf(
                ' از این میان %s فروش به مبلغ %s روی حساب نشسته — آن پول'
                    .' در بانک می‌ماند و دفتر دیگر نمی‌داند بابت چیست.',
                number_format($counted['banked']),
                Money::format($counted['banked_amount']),
            );
        }

        return $warning.' بعد از اصلاح، فروش‌های آن روز را دوباره وارد کنید.';
    }
}
