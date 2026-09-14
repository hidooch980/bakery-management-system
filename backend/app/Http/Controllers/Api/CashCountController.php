<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CashCount;
use App\Support\AppCalendar;
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
 */
class CashCountController extends Controller
{
    use ApiResponse;

    /** How many counts the history shows. A season's worth. */
    private const HISTORY = 60;

    public function index(): JsonResponse
    {
        $till = BankAccount::cashBox();

        if (! $till) {
            return $this->error('حسابی به‌عنوان صندوق نقد تعیین نشده.', 422);
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
        ]);

        $till = BankAccount::cashBox();

        if (! $till) {
            return $this->error('حسابی به‌عنوان صندوق نقد تعیین نشده.', 422);
        }

        $count = DB::transaction(function () use ($data, $request, $till) {
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
                    'اصلاح پس از شمارش صندوق',
                );
            }

            return $count;
        });

        return $this->success(
            $this->present($count->fresh(['user', 'adjustment'])),
            $count->is_exact
                ? 'شمارش ثبت شد — صندوق با دفتر می‌خواند.'
                : 'شمارش ثبت شد.',
            201
        );
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
