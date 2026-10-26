<?php

namespace App\Support;

use App\Models\BankSms;
use App\Models\BankTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * «تکراری ثبت نشه»: salaries, flour purchases, instalments, advances and
 * the card-terminal deposits are already on the books when their SMS
 * arrives. Every one of them goes through BankAccount::record(), so the
 * bank_transactions table is the one place to look.
 *
 * A movement is the same as an SMS when it is on the same account (when
 * the card is mapped), in the same direction, for exactly the same amount,
 * within a day either side, and not already claimed by another SMS.
 * One candidate links automatically; several are left for a person to pick.
 */
class BankSmsMatcher
{
    public const WINDOW_DAYS = 1;

    /** @return Collection<int, BankTransaction> */
    public static function candidates(BankSms $sms): Collection
    {
        if ($sms->amount === null || $sms->direction === 'unknown') {
            return collect();
        }

        $day = Carbon::parse($sms->received_at)->startOfDay();

        return BankTransaction::query()
            ->when($sms->bank_account_id, fn ($q) => $q->where('bank_account_id', $sms->bank_account_id))
            ->where('direction', $sms->direction)
            ->where('amount', $sms->amount)
            ->whereBetween('occurred_on', [
                $day->copy()->subDays(self::WINDOW_DAYS)->toDateString(),
                $day->copy()->addDays(self::WINDOW_DAYS)->toDateString(),
            ])
            ->whereNotIn('id', BankSms::query()->whereNotNull('bank_transaction_id')
                ->where('id', '!=', $sms->id ?? 0)->select('bank_transaction_id'))
            ->orderBy('id')
            ->get();
    }

    /** Decide a fresh SMS: matched, ambiguous, or pending. */
    public static function settle(BankSms $sms): BankSms
    {
        $found = self::candidates($sms);

        if ($found->count() === 1) {
            $sms->forceFill(['status' => 'matched', 'bank_transaction_id' => $found->first()->id]);
        } elseif ($found->count() > 1) {
            $sms->forceFill(['status' => 'ambiguous']);
        } else {
            $sms->forceFill(['status' => 'pending']);
        }

        $sms->save();

        return $sms;
    }

    /**
     * The other way round: a movement recorded by hand after its SMS came
     * in takes the one open SMS it fits, so the SMS leaves the inbox.
     */
    public static function claim(BankTransaction $tx): ?BankSms
    {
        $date = Carbon::parse($tx->occurred_on)->startOfDay();

        $open = BankSms::query()
            ->whereIn('status', BankSms::OPEN)
            ->whereNull('bank_transaction_id')
            ->where('direction', $tx->direction)
            ->where('amount', $tx->amount)
            ->where(fn ($q) => $q->whereNull('bank_account_id')->orWhere('bank_account_id', $tx->bank_account_id))
            ->whereBetween('received_at', [
                $date->copy()->subDays(self::WINDOW_DAYS),
                $date->copy()->addDays(self::WINDOW_DAYS)->endOfDay(),
            ])
            ->get();

        if ($open->count() !== 1) {
            return null;
        }

        $sms = $open->first();
        $sms->forceFill(['status' => 'matched', 'bank_transaction_id' => $tx->id])->save();

        return $sms;
    }

    /** «حقوق — جلیل درازهی»: what an SMS turned out to be. */
    public static function describe(BankTransaction $tx): string
    {
        $who = $tx->source?->user?->name
            ?? $tx->source?->supplier?->name
            ?? $tx->source?->customer?->name
            ?? $tx->source?->title
            ?? $tx->note;

        return trim($tx->reason_label.($who ? ' — '.$who : ''));
    }
}
