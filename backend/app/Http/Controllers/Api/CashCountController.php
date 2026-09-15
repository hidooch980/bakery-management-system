<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashCount;
use App\Support\AppCalendar;
use App\Support\CashNeverBanked;
use App\Support\CurrentBakery;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «چقدر پول در کشو هست؟» — asked of the drawer, answered by the books.
 *
 * Every hole closed this month was about money reaching the till. None of
 * it says whether the figure is true: change is given from the same
 * drawer, notes are handed over in a hurry, and a sale typed at the wrong
 * price leaves a gap both sides of the ledger agree about.
 *
 * The same question is worth asking of a bank account, and for a while
 * nothing here could. The shop's «حساب سفید» drifted 70,292,603 Toman
 * from the bank by 1405/05, and the answer was a hand-typed withdrawal
 * labelled «برداشت شخصی: اختلاف» — the gap closed without the cause ever
 * being found. A month later it had reopened at 13,022,850.
 *
 * A gap one week wide can be traced; a gap three months wide cannot, and
 * the window is what decides that, not the size of the number. So the
 * account is now the caller's to name, and the till is only the default.
 * Everything else — the history, the «adjust» decision, the audit line —
 * was never cash-specific and is reused as it stands.
 */
class CashCountController extends Controller
{
    use ApiResponse;

    /** How many counts the history shows. A season's worth. */
    private const HISTORY = 60;

    /**
     * The account being counted: the one asked for, or the till.
     *
     * Scoped to the shop by `BelongsToBakery`, so an id from another
     * bakery finds nothing rather than counting somebody else's money.
     * Inactive accounts are refused too — a closed account has no balance
     * worth arguing with, and counting one would only add a row nobody
     * will read.
     */
    private function accountFor(Request $request): ?BankAccount
    {
        $id = $request->integer('account_id') ?: null;

        if ($id === null) {
            return BankAccount::cashBox();
        }

        return BankAccount::query()
            ->where('id', $id)
            ->where('is_active', true)
            ->first();
    }

    /** Said differently depending on what was actually asked for. */
    private function noAccountMessage(Request $request): string
    {
        return $request->integer('account_id')
            ? 'این حساب پیدا نشد یا فعال نیست.'
            : 'حسابی به‌عنوان صندوق نقد تعیین نشده.';
    }

    public function index(Request $request): JsonResponse
    {
        $till = $this->accountFor($request);

        if (! $till) {
            return $this->error($this->noAccountMessage($request), 422);
        }

        $counts = CashCount::with('user')
            ->where('bank_account_id', $till->id)
            ->latest('counted_at')
            ->limit(self::HISTORY)
            ->get();

        $expected = round((float) $till->balance, 2);
        $last = $counts->first();

        return $this->success([
            'account' => ['id' => $till->id, 'title' => $till->title],
            // Every account worth asking the question of, so the screen
            // can offer a choice instead of hard-coding the till the way
            // this controller used to.
            'accounts' => BankAccount::query()
                ->where('is_active', true)
                ->orderByDesc('is_cash_box')
                ->orderBy('title')
                ->get()
                ->map(fn (BankAccount $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'is_cash_box' => (bool) $a->is_cash_box,
                ])
                ->values(),
            // The first count is not a measurement of how well the shop is
            // keeping its books — it is the moment the drawer gets a true
            // figure for the first time.
            'first_count' => $counts->isEmpty(),
            'ledger_behind' => $this->ledgerBehind($counts->isEmpty()),
            // What the books say right now, so the screen can show the
            // figure to count against before anything is typed.
            'expected' => Money::convert($expected),
            'expected_formatted' => Money::format($expected),
            'last_counted_at' => $last ? AppCalendar::date($last->counted_at) : null,
            'days_since_count' => $last
                ? (int) $last->counted_at->startOfDay()->diffInDays(now()->startOfDay())
                : null,
            'counts' => $counts->map(fn (CashCount $c) => $this->present($c))->values(),
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            // Whether to make the books agree with the drawer. Off by
            // default and always the caller's decision: adjusting quietly
            // would hide the very thing this screen exists to show.
            'adjust' => ['nullable', 'boolean'],
            // Which account is being counted. Absent means the till, so
            // every app version already installed keeps working unchanged.
            'account_id' => ['nullable', 'integer'],
        ]);

        $till = $this->accountFor($request);

        if (! $till) {
            return $this->error($this->noAccountMessage($request), 422);
        }

        $first = ! CashCount::where('bank_account_id', $till->id)->exists();

        $count = DB::transaction(function () use ($data, $request, $till, $first) {
            // Read inside the transaction, so the figure written as
            // «expected» is the one the adjustment below is computed from.
            $expected = round((float) $till->balance, 2);

            $count = CashCount::create([
                'bank_account_id' => $till->id,
                'user_id' => $request->user()->id,
                'counted_amount' => Money::toToman($data['counted_amount']),
                'expected_amount' => $expected,
                'counted_at' => now(),
                'note' => $data['note'] ?? null,
            ]);

            if (($data['adjust'] ?? false) && ! $count->is_exact) {
                $gap = $count->difference;

                $till->record(
                    $gap > 0 ? 'in' : 'out',
                    abs($gap),
                    'manual',
                    $request->user()->id,
                    $count,
                    // Named for what it actually is. Calling the first one
                    // an «اصلاح» would file the whole un-posted history of
                    // the shop as this afternoon's mistake.
                    // A drawer is counted; a bank account is read off a
                    // statement. Filing the second under «شمارش صندوق»
                    // would make the history unreadable the day somebody
                    // asks which account this line belonged to.
                    $this->adjustmentNote($till, $first),
                );
            }

            return $count;
        });

        return $this->success(
            $this->present($count->fresh(['user', 'adjustment'])),
            $count->is_exact
                ? ($till->is_cash_box
                    ? 'شمارش ثبت شد — صندوق با دفتر می‌خواند.'
                    : 'ثبت شد — بانک با دفتر می‌خواند.')
                : 'ثبت شد.',
            201
        );
    }

    /** What the correcting transaction is called, in the account's own terms. */
    private function adjustmentNote(BankAccount $account, bool $first): string
    {
        if ($account->is_cash_box) {
            return $first
                ? 'موجودی اولیهٔ صندوق، پس از اولین شمارش'
                : 'اصلاح پس از شمارش صندوق';
        }

        return $first
            ? "موجودی اولیهٔ «{$account->title}»، پس از اولین بررسی"
            : "اصلاح پس از بررسی «{$account->title}»";
    }

    /**
     * Why the books are behind the drawer, before the first count.
     *
     * The till opened at zero and years of cash never reached it: every
     * counter sale of flour that named no account, every handover whose
     * cash share was validated and dropped. So the first count will read
     * «اضافه» by something close to the shop's whole history, and read on
     * its own that says the drawer is over — which it is not. It says the
     * ledger never had the money.
     *
     * Only asked before the first count. Afterwards the opening figure is
     * in the books and a gap means what it says.
     *
     * @return array<string, mixed>|null
     */
    private function ledgerBehind(bool $firstCount): ?array
    {
        $bakery = CurrentBakery::get();

        if (! $firstCount || ! $bakery) {
            return null;
        }

        $audit = CashNeverBanked::auditFor($bakery);

        $known = $audit['flour_toman'] + $audit['settlement_toman'];
        $behind = $known + $audit['unrecorded_toman'];

        if ($behind <= 0) {
            return null;
        }

        return [
            'amount' => Money::convert($behind),
            'amount_formatted' => Money::format($behind),
            'flour_sales' => $audit['flour_sales'],
            'settlements' => $audit['settlements'],
            // Told apart because they are answered differently: the known
            // share could be posted from the rows it came from, the rest
            // cannot be recovered from anywhere and only counting settles it.
            'known_formatted' => Money::format($known),
            'unrecorded_formatted' => Money::format($audit['unrecorded_toman']),
            'message' => 'این اولین شمارش است و دفتر هنوز عقب است: پول نقدی که'
                .' در گذشته تحویل گرفته شده و هیچ‌وقت به حساب صندوق ننشسته.'
                .' پس اختلاف این بار «اضافه» نشان می‌دهد و ایراد شما نیست —'
                .' عددی که می‌شمارید موجودی درست صندوق است.',
        ];
    }

    /** @return array<string, mixed> */
    private function present(CashCount $count): array
    {
        $gap = $count->difference;

        return [
            'id' => $count->id,
            'counted' => Money::convert((float) $count->counted_amount),
            'counted_formatted' => Money::format((float) $count->counted_amount),
            'expected' => Money::convert((float) $count->expected_amount),
            'expected_formatted' => Money::format((float) $count->expected_amount),
            'difference' => Money::convert($gap),
            'difference_formatted' => Money::format(abs($gap)),
            // Said in words as well as sign, because «‎-۱۲۳٬۰۰۰» read on a
            // phone in a hurry is the one figure nobody should misread.
            'difference_label' => $count->is_exact
                ? 'می‌خواند'
                : ($gap > 0 ? 'اضافه' : 'کسری'),
            'is_exact' => $count->is_exact,
            'adjusted' => $count->adjustment !== null,
            'counted_at' => AppCalendar::date($count->counted_at),
            'counted_by' => $count->user?->name,
            'note' => $count->note,
        ];
    }
}
