<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bakery;
use App\Models\ChaneEntry;
use App\Models\Sale;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    use ApiResponse;

    public const PAYMENT_TYPES = ['cash', 'card', 'credit', 'home', 'schools', 'other'];

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
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],

            'payments' => ['nullable', 'array', 'min:1'],
            'payments.*.payment_type' => ['required', 'in:'.implode(',', self::PAYMENT_TYPES)],
            'payments.*.bread_count' => ['required', 'integer', 'min:1', 'max:1000000'],
            'payments.*.amount' => ['nullable', 'numeric', 'min:0'],
            'payments.*.customer_id' => ['nullable', 'exists:customers,id'],
        ]);

        $chane = ChaneEntry::find($data['chane_entry_id']);

        if ($chane->status !== 'pending') {
            return $this->error('این چانه قبلاً فروخته شده است.', 409);
        }

        $lines = $this->paymentLines($data, $chane);

        // Sales to schools or offices should name the buyer.
        foreach ($lines as $line) {
            if (in_array($line['payment_type'], ['schools', 'credit'], true)
                && empty($line['customer_id'])) {
                return $this->error('برای این نوع پرداخت، انتخاب مشتری الزامی است.', 422);
            }
        }

        $totalBread = array_sum(array_column($lines, 'bread_count'));

        if ($totalBread > $chane->chane_count) {
            return $this->error(
                'مجموع تعداد نان ('.number_format($totalBread).') از تعداد چانه این دسته ('
                .number_format($chane->chane_count).') بیشتر است.',
                422
            );
        }

        $sales = DB::transaction(function () use ($lines, $chane, $request, $totalBread) {
            $breadPrice = (float) (Bakery::first()->bread_price ?? 0);

            // Whatever the batch held beyond everything sold from it is a
            // temporary debt against the seller — computed from the batch's
            // own count, never from client input, so it can't be typed
            // away. Counted once for the batch rather than once per line.
            $shortfallCount = max(0, $chane->chane_count - $totalBread);
            $shortfallApplied = false;

            $created = [];

            foreach ($lines as $line) {
                $amount = $line['amount'];

                // How far the money taken sits from what this bread should
                // have cost. Frozen here rather than recomputed, so a later
                // price change cannot rewrite what a seller already owed.
                $difference = ($amount === null || $breadPrice <= 0)
                    ? null
                    : round((float) $amount - $line['bread_count'] * $breadPrice, 2);

                $created[] = Sale::create([
                    'chane_entry_id' => $chane->id,
                    'user_id' => $request->user()->id,
                    'payment_type' => $line['payment_type'],
                    'bread_count' => $line['bread_count'],
                    // The batch's shortfall belongs to the batch, so it
                    // rides on the first line only and is never doubled.
                    'shortfall_count' => (! $shortfallApplied && $shortfallCount > 0)
                        ? $shortfallCount
                        : null,
                    'shortfall_amount' => (! $shortfallApplied && $shortfallCount > 0)
                        ? round($shortfallCount * $breadPrice, 2)
                        : null,
                    'amount_difference' => $difference,
                    'customer_id' => $line['customer_id'],
                    'amount' => $amount,
                    'note' => $line['note'],
                ]);

                $shortfallApplied = true;
            }

            $chane->update(['status' => 'sold']);

            return $created;
        });

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
     * @return array<int, array{payment_type: string, bread_count: int, amount: float|null, customer_id: int|null, note: string|null}>
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
                'note' => $note,
            ]];
        }

        return array_map(fn (array $line) => [
            'payment_type' => $line['payment_type'],
            'bread_count' => (int) $line['bread_count'],
            'amount' => isset($line['amount']) ? (float) $line['amount'] : null,
            // A line may name its own buyer; otherwise the sale's does.
            'customer_id' => $line['customer_id'] ?? $data['customer_id'] ?? null,
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
                'total_amount_formatted' => \App\Support\Money::format($sales->sum('amount')),
                'currency' => \App\Support\Money::currency(),
                'currency_label' => \App\Support\Money::label(),
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
}
