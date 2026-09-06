<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Exclusively;
use App\Support\Money;
use App\Support\SaleRecorder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    use ApiResponse;

    // 'charity' is bread given away — to a mosque, a religious school or
    // anyone in need. It moves real bread but brings in no money, so it
    // is a payment type of its own rather than a sale recorded at zero,
    // which would read as a shortfall the seller has to answer for.
    /**
     * What a seller may choose when recording a sale.
     *
     * 'shortfall' and 'other' are gone from here, not from the system. A
     * shortfall is worked out on its own — the batch's chane count less
     * what was accounted for — so offering it as a payment type asked the
     * seller to name something the shop already knows, and in the whole
     * history of this shop neither was ever picked once.
     *
     * Sale::SHORTFALL_TYPES stays: rows of that type could exist on an
     * older installation, and the queries that exclude them must keep
     * excluding them.
     */
    public const PAYMENT_TYPES = ['cash', 'card', 'credit', 'home', 'schools', 'charity', 'waste'];

    /**
     * Seller records the sale of a pending chane batch.
     *
     * A batch is often paid for in more than one way — part cash, part
     * card — so the seller may send a `payments` list with a bread count
     * per type instead of a single payment_type. Each line still becomes
     * its own Sale row, which keeps every report that groups by payment
     * type working unchanged; they are simply written together so the
     * batch is closed once and the shortfall counted once.
     *
     * The single payment_type form is still accepted, so an older copy of
     * the app keeps working after the server is updated.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chane_entry_id' => ['required', 'exists:chane_entries,id'],
            'payment_type' => ['required_without:payments', 'in:'.implode(',', self::PAYMENT_TYPES)],
            'bread_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            // Who took the bread home, for a «منزل» line. The seller names
            // them; the server charges that person's payslip, not the
            // seller's account.
            'consumed_by_user_id' => ['nullable', 'exists:users,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],

            'payments' => ['nullable', 'array', 'min:1'],
            'payments.*.payment_type' => ['required', 'in:'.implode(',', self::PAYMENT_TYPES)],
            'payments.*.bread_count' => ['required', 'integer', 'min:1', 'max:1000000'],
            'payments.*.amount' => ['nullable', 'numeric', 'min:0'],
            'payments.*.customer_id' => ['nullable', 'exists:customers,id'],
            'payments.*.consumed_by_user_id' => ['nullable', 'exists:users,id'],
        ]);

        // The batch is locked for the whole of this, and the "already
        // sold" question is asked of the locked copy. Asked of the copy
        // read a moment earlier — which is how it was written — two
        // sellers tapping the same batch both saw `pending`, both passed,
        // and both sold it: bread out of the warehouse twice and the
        // money charged against the seller twice.
        $sales = Exclusively::claim(
            ChaneEntry::findOrFail($data['chane_entry_id']),
            fn (ChaneEntry $chane) => $chane->status === 'pending'
                ? null
                : 'این چانه قبلاً فروخته شده است.',
            function (ChaneEntry $chane) use ($data, $request) {
                $lines = $this->paymentLines($data, $chane);

                // Inside the lock too: it reads what has already been sold
                // from this batch, and that is the figure a second seller
                // would otherwise be racing.
                if ($problem = SaleRecorder::problemWith($chane, $lines)) {
                    throw ValidationException::withMessages(['sale' => [$problem]]);
                }

                return SaleRecorder::record($chane, $lines, $request->user()->id);
            },
        );

        // One line still answers with that single sale, so nothing that
        // already reads data.id or data.amount has to change.
        return $this->success(
            count($sales) === 1 ? $sales[0] : ['sales' => $sales],
            'فروش ثبت شد.',
            201
        );
    }

    /**
     * Normalises either request shape into one list of payment lines.
     *
     * @return array<int, array{payment_type: string, bread_count: int, amount: float|null, customer_id: int|null, consumed_by_user_id: int|null, note: string|null}>
     */
    private function paymentLines(array $data, ChaneEntry $chane): array
    {
        $note = $data['note'] ?? null;

        if (empty($data['payments'])) {
            return [[
                'payment_type' => $data['payment_type'],
                // Default to the batch size when the seller did not split it.
                'bread_count' => (int) ($data['bread_count'] ?? $chane->chane_count),
                'amount' => isset($data['amount']) ? (float) $data['amount'] : null,
                'customer_id' => $data['customer_id'] ?? null,
                'consumed_by_user_id' => $data['consumed_by_user_id'] ?? null,
                'note' => $note,
            ]];
        }

        return array_map(fn (array $line) => [
            'payment_type' => $line['payment_type'],
            'bread_count' => (int) $line['bread_count'],
            'amount' => isset($line['amount']) ? (float) $line['amount'] : null,
            // A line may name its own buyer; otherwise the sale's does.
            'customer_id' => $line['customer_id'] ?? $data['customer_id'] ?? null,
            // Same for who took it home: a split sale can send one line
            // home with one worker and sell the rest for cash.
            'consumed_by_user_id' => $line['consumed_by_user_id'] ?? $data['consumed_by_user_id'] ?? null,
            'note' => $note,
        ], $data['payments']);
    }

    /**
     * The seller's sales for the current day.
     */
    public function today(Request $request): JsonResponse
    {
        $sales = Sale::where('user_id', $request->user()->id)
            ->whereDate('created_at', now()->toDateString())
            ->with(['chaneEntry:id,chane_count', 'customer:id,name,type'])
            ->latest()
            ->get();

        return $this->success([
            'sales' => $sales,
            'summary' => [
                'count' => $sales->count(),
                'bread_count' => (int) $sales->sum('bread_count'),
                'total_amount' => round((float) $sales->sum('amount'), 2),
                'total_amount_formatted' => Money::format($sales->sum('amount')),
                'currency' => Money::currency(),
                'currency_label' => Money::label(),
                'by_payment_type' => $sales->groupBy('payment_type')->map(fn ($g) => [
                    'count' => $g->count(),
                    'bread_count' => (int) $g->sum('bread_count'),
                    'amount' => round((float) $g->sum('amount'), 2),
                ]),
            ],
        ]);
    }

    public function paymentTypes(): JsonResponse
    {
        return $this->success(self::PAYMENT_TYPES);
    }

    /**
     * Who the seller can name as having taken bread home.
     *
     * The attendance roster already lists the staff, but it deliberately
     * leaves the asker out — it exists so somebody ticks in the people who
     * are not holding a phone. A seller takes bread home like anyone else,
     * so this one includes them.
     */
    public function staff(): JsonResponse
    {
        return $this->success(
            User::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    /**
     * The seller's own temporary account, so they can see what they are
     * answerable for rather than finding out at the end of the month.
     *
     * Read-only on purpose: a seller clearing their own debt would defeat
     * the point of recording it. Settling stays with the admin.
     */
    public function myAccount(Request $request): JsonResponse
    {
        $sales = Sale::query()
            ->where('user_id', $request->user()->id)
            ->sellerAccountOutstanding()
            ->with('customer:id,name')
            ->latest()
            ->get();

        $cash = round($sales->sum(fn (Sale $s) => $s->cash_held), 2);
        $difference = round($sales->sum(fn (Sale $s) => $s->open_difference), 2);
        $shortfall = round($sales->sum(fn (Sale $s) => $s->open_shortfall), 2);
        $credit = round($sales->sum(fn (Sale $s) => $s->open_credit), 2);
        $total = round($sales->sum(fn (Sale $s) => $s->seller_account_amount), 2);

        return $this->success([
            'cash' => $cash,
            'cash_formatted' => Money::format($cash),
            'difference' => $difference,
            'difference_formatted' => Money::format($difference),
            'shortfall' => $shortfall,
            'shortfall_formatted' => Money::format($shortfall),
            'shortfall_count' => (int) $sales->sum(
                fn (Sale $s) => $s->open_shortfall > 0 ? $s->shortfall_count : 0
            ),
            'credit' => $credit,
            'credit_formatted' => Money::format($credit),
            'total' => $total,
            'total_formatted' => Money::format($total),
            // What the seller can hand over today. Credit is the customer's
            // to pay, so it is on the account but not settleable, and the
            // app needs the difference to prefill its settlement form.
            'settleable' => round($cash + $shortfall - $difference, 2),
            'settleable_formatted' => Money::format($cash + $shortfall - $difference),
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
            'entries' => $sales->count(),
            'credit_sales' => $sales->where('open_credit', '>', 0)->map(fn (Sale $s) => [
                'id' => $s->id,
                'customer' => $s->customer?->name,
                'bread_count' => (int) $s->bread_count,
                'amount_formatted' => Money::format((float) $s->amount),
                'date_display' => AppCalendar::date($s->created_at),
            ])->values(),
        ]);
    }
}
