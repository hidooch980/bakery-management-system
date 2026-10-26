<?php

namespace App\Support;

use App\Exceptions\DuplicateRecord;
use App\Models\BankAccount;
use App\Models\BankSms;
use App\Models\BankSmsAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Income;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that can happen to a bank SMS, shared by the app's API and
 * the panel page so the two can never book it differently.
 *
 * Nothing is booked on arrival. An SMS is either already on the books
 * (matched), possibly on them (ambiguous: someone picks), or waits for
 * «ثبت» or «نادیده». «ثبت» creates the Income or Expense exactly as the
 * manual forms do, and the movement that posts is linked to this SMS.
 */
class BankSmsBook
{
    /** Minutes within which the same amount twice looks like one payment. */
    public const DUPLICATE_MINUTES = 10;

    /**
     * Files one SMS from the phone. The same message twice is filed once.
     *
     * @return array{0: ?BankSms, 1: string} the SMS and created|duplicate|not_bank
     */
    public static function ingest(User $user, string $sender, string $body, $receivedAt): array
    {
        $parsed = BankSmsParser::parse($sender, $body);

        if ($parsed === null || $parsed['amount'] === null || $parsed['direction'] === 'unknown') {
            return [null, 'not_bank'];
        }

        $at = Carbon::parse($receivedAt);
        $hash = BankSms::hashOf($sender, $body, $at);

        if ($existing = BankSms::query()->where('hash', $hash)->first()) {
            return [$existing, 'duplicate'];
        }

        $sms = DB::transaction(function () use ($user, $sender, $body, $at, $hash, $parsed) {
            $sms = BankSms::create([
                'user_id' => $user->id,
                'sender' => mb_substr($sender, 0, 40),
                'bank' => $parsed['bank'],
                'body' => $body,
                'received_at' => $at,
                'hash' => $hash,
                'direction' => $parsed['direction'],
                'kind' => $parsed['kind'],
                'amount' => $parsed['amount'] === null ? null : (string) $parsed['amount'],
                'balance' => $parsed['balance'] === null ? null : (string) $parsed['balance'],
                'last4' => $parsed['last4'],
                'bank_account_id' => BankSmsAccount::accountFor($parsed['last4']),
                'suggested_category' => $parsed['suggested_category'],
            ]);

            BankSmsMatcher::settle($sms);
            self::applyBalance($sms);

            return $sms;
        });

        return [$sms, 'created'];
    }

    /**
     * «ثبت درآمد» / «ثبت هزینه». Builds the record the manual form would.
     *
     * @throws DuplicateRecord when a twin is on the books and $force is off
     */
    public static function confirm(BankSms $sms, User $user, string $category, ?int $accountId = null, ?string $note = null, bool $force = false): BankSms
    {
        self::mustBeOpen($sms);

        $in = $sms->direction === 'in';
        $categories = $in ? Income::CATEGORIES : Expense::CATEGORIES;

        if (! array_key_exists($category, $categories)) {
            throw ValidationException::withMessages(['category' => 'دستهٔ انتخاب‌شده درست نیست.']);
        }

        $accountId ??= $sms->bank_account_id;

        if ($accountId === null || ! BankAccount::query()->whereKey($accountId)->exists()) {
            throw ValidationException::withMessages(['bank_account_id' => 'حساب بانکی این پیامک را انتخاب کنید.']);
        }

        $title = trim(($sms->bank ? 'پیامک بانک '.$sms->bank : 'پیامک بانکی').' — '.$categories[$category]);
        $day = Carbon::parse($sms->received_at)->toDateString();

        return DB::transaction(function () use ($sms, $user, $in, $category, $accountId, $note, $force, $title, $day) {
            BankSmsMatcher::$pinned = $sms->id;

            try {
                $record = $in
                    ? Income::create([
                        'user_id' => $user->id, 'category' => $category, 'title' => $title,
                        'amount' => $sms->amount, 'received_on' => $day,
                        'bank_account_id' => $accountId, 'note' => $note,
                    ])
                    : Expense::create([
                        'user_id' => $user->id, 'category' => $category, 'title' => $title,
                        'amount' => $sms->amount, 'spent_on' => $day,
                        'bank_account_id' => $accountId, 'note' => $note,
                    ]);

                if (! $force && $twin = $record->twin()) {
                    throw new DuplicateRecord($twin);
                }
            } finally {
                BankSmsMatcher::$pinned = null;
            }

            $sms->refresh()->forceFill([
                'status' => 'confirmed',
                'bank_account_id' => $accountId,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ])->save();

            return $sms;
        });
    }

    public static function ignore(BankSms $sms, User $user): BankSms
    {
        self::mustBeOpen($sms);

        $sms->forceFill(['status' => 'ignored', 'decided_by' => $user->id, 'decided_at' => now()])->save();

        return $sms;
    }

    /** «همین است»: the SMS is a movement already on the books. */
    public static function link(BankSms $sms, User $user, int $transactionId): BankSms
    {
        self::mustBeOpen($sms);

        if (! BankSmsMatcher::candidates($sms)->contains('id', $transactionId)) {
            throw ValidationException::withMessages(['bank_transaction_id' => 'این تراکنش با پیامک جور نیست یا قبلاً به پیامک دیگری وصل شده.']);
        }

        $sms->forceFill([
            'status' => 'matched',
            'bank_transaction_id' => $transactionId,
            'decided_by' => $user->id,
            'decided_at' => now(),
        ])->save();

        return $sms;
    }

    /**
     * «بعد از اولین پیامک موجودی بروز بشه»: the account takes the balance
     * the bank reported, unless a newer SMS already gave one. Nothing is
     * booked: the ledger is left exactly as it was.
     */
    public static function applyBalance(BankSms $sms): void
    {
        if ($sms->bank_account_id === null || $sms->balance === null) {
            return;
        }

        BankAccount::query()->whereKey($sms->bank_account_id)
            ->where(fn ($q) => $q->whereNull('sms_balance_at')->orWhere('sms_balance_at', '<=', $sms->received_at))
            ->update(['sms_balance' => $sms->balance, 'sms_balance_at' => $sms->received_at]);
    }

    /** The latest SMS on these digits sets the newly tied account. */
    public static function applyLatestBalanceFor(string $last4): void
    {
        $latest = BankSms::query()->where('last4', $last4)->whereNotNull('balance')->latest('received_at')->first();
        if ($latest) {
            $latest->bank_account_id ??= BankSmsAccount::accountFor($last4);
            self::applyBalance($latest);
        }
    }

    /** Bank-reported balances, for the home card and the panel. */
    public static function balances(): array
    {
        return BankAccount::query()->whereNotNull('sms_balance_at')->orderBy('title')->get()
            ->map(fn (BankAccount $a) => [
                'bank_account_id' => $a->id,
                'account_name' => $a->title,
                'balance' => (float) $a->sms_balance,
                'at' => $a->sms_balance_at->toIso8601String(),
                'at_jalali' => Jalali::format($a->sms_balance_at, 'Y/m/d H:i'),
            ])->values()->all();
    }

    /** A record deleted after its SMS was settled puts the SMS back. */
    public static function reopenOrphans(): void
    {
        BankSms::query()
            ->whereIn('status', ['matched', 'confirmed'])
            ->whereNull('bank_transaction_id')
            ->get()
            ->each(fn (BankSms $sms) => BankSmsMatcher::settle($sms));
    }

    /** What the app and the panel show. The account number is masked. */
    public static function present(BankSms $sms): array
    {
        $in = $sms->direction === 'in';
        $categories = $in ? Income::CATEGORIES : Expense::CATEGORIES;
        $open = in_array($sms->status, BankSms::OPEN, true);

        $twin = $open ? BankSms::query()
            ->whereKeyNot($sms->id)
            ->where('direction', $sms->direction)
            ->where('amount', $sms->amount)
            ->whereBetween('received_at', [
                Carbon::parse($sms->received_at)->subMinutes(self::DUPLICATE_MINUTES),
                Carbon::parse($sms->received_at)->addMinutes(self::DUPLICATE_MINUTES),
            ])
            ->where('id', '<', $sms->id)
            ->first() : null;

        return [
            'id' => $sms->id,
            'bank' => $sms->bank,
            'body' => BankSmsParser::mask($sms->body),
            'received_at' => Carbon::parse($sms->received_at)->toIso8601String(),
            'received_at_jalali' => Jalali::format(Carbon::parse($sms->received_at), 'Y/m/d H:i'),
            'direction' => $sms->direction,
            'kind' => $sms->kind,
            'amount' => (float) $sms->amount,
            'balance' => $sms->balance === null ? null : (float) $sms->balance,
            'last4' => $sms->last4,
            'bank_account_id' => $sms->bank_account_id,
            'account_name' => $sms->account?->title,
            'suggested_category' => $sms->suggested_category,
            'suggested_category_label' => $categories[$sms->suggested_category] ?? null,
            'status' => $sms->status,
            'matched_label' => $sms->transaction ? BankSmsMatcher::describe($sms->transaction) : null,
            'candidates' => $sms->status === 'ambiguous'
                ? BankSmsMatcher::candidates($sms)->map(fn (BankTransaction $tx) => [
                    'id' => $tx->id,
                    'label' => BankSmsMatcher::describe($tx),
                    'occurred_on' => (string) $tx->occurred_on,
                ])->values()->all()
                : [],
            'possible_duplicate_of' => $twin ? [
                'id' => $twin->id,
                'at' => Carbon::parse($twin->received_at)->format('H:i'),
            ] : null,
        ];
    }

    private static function mustBeOpen(BankSms $sms): void
    {
        if (! in_array($sms->status, BankSms::OPEN, true) || $sms->bank_transaction_id !== null) {
            throw ValidationException::withMessages(['status' => 'این پیامک قبلاً تعیین تکلیف شده است.']);
        }
    }
}
