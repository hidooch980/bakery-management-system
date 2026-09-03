<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\SaleResource;
use App\Http\Controllers\Controller;
use App\Models\DoughEntry;
use App\Models\Expense;
use App\Models\FlourSale;
use App\Models\Income;
use App\Models\InventoryMovement;
use App\Models\SalaryPayment;
use App\Models\Sale;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Flat, one-row-per-record datasets for tools that build their own model.
 *
 * Power BI wants long tables it can relate and aggregate itself, not the
 * nested summaries the app screens read. Everything here is therefore one
 * row per real-world event, with the Jalali date carried alongside the
 * Gregorian one so the report can be sliced on the calendar the shop
 * actually works to.
 */
class PowerBiExportController extends Controller
{
    use ApiResponse;

    public const DATASETS = ['sales', 'expenses', 'income', 'production', 'inventory', 'salaries'];

    /** A pull this size already covers years; past it, ask for a range. */
    private const MAX_ROWS = 20000;

    public function show(Request $request, string $dataset): JsonResponse
    {
        if (! in_array($dataset, self::DATASETS, true)) {
            return $this->error(
                'مجموعه داده نامعتبر است. یکی از این‌ها را انتخاب کنید: '
                    .implode('، ', self::DATASETS),
                404
            );
        }

        [$from, $to] = $this->range($request);

        $rows = match ($dataset) {
            'sales' => $this->sales($from, $to),
            'expenses' => $this->expenses($from, $to),
            'income' => $this->income($from, $to),
            'production' => $this->production($from, $to),
            'inventory' => $this->inventory($from, $to),
            'salaries' => $this->salaries($from, $to),
        };

        return $this->success([
            'dataset' => $dataset,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => Money::currency(),
            'row_count' => count($rows),
            // Says so plainly rather than quietly returning a partial table
            // that a refresh would then report as a drop in the figures.
            'truncated' => count($rows) >= self::MAX_ROWS,
            'rows' => $rows,
        ]);
    }

    private function sales(Carbon $from, Carbon $to): array
    {
        return Sale::with(['user:id,name', 'customer:id,name,type'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'date' => $sale->created_at->toDateString(),
                'date_jalali' => Jalali::date($sale->created_at),
                'time' => $sale->created_at->format('H:i'),
                'payment_type' => $sale->payment_type,
                'payment_label' => SaleResource::PAYMENT_LABELS[$sale->payment_type] ?? $sale->payment_type,
                'bread_count' => (int) $sale->bread_count,
                'amount' => round((float) $sale->amount, 2),
                'seller' => $sale->user?->name,
                'customer' => $sale->customer?->name,
                'customer_type' => $sale->customer?->type,
                'settled' => $sale->settled_on !== null,
            ])
            ->all();
    }

    private function expenses(Carbon $from, Carbon $to): array
    {
        return Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->orderBy('spent_on')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => $expense->id,
                'date' => Carbon::parse($expense->spent_on)->toDateString(),
                'date_jalali' => Jalali::date($expense->spent_on),
                'category' => $expense->category,
                'category_label' => Expense::categoryLabels()[$expense->category] ?? $expense->category,
                'amount' => round((float) $expense->amount, 2),
                'note' => $expense->note,
            ])
            ->all();
    }

    private function income(Carbon $from, Carbon $to): array
    {
        $dates = [$from->toDateString(), $to->toDateString()];

        // Flour sales and miscellaneous receipts are money in just as much
        // as bread is, so they arrive in one table the report can total
        // without having to union three endpoints itself.
        $flour = FlourSale::whereBetween('sold_on', $dates)
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (FlourSale $sale) => [
                'id' => 'flour-'.$sale->id,
                'date' => Carbon::parse($sale->sold_on)->toDateString(),
                'date_jalali' => Jalali::date($sale->sold_on),
                'source' => 'flour_sale',
                'source_label' => 'فروش آرد',
                'amount' => round((float) $sale->amount, 2),
                'note' => $sale->note,
            ]);

        $other = Income::whereBetween('received_on', $dates)
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Income $income) => [
                'id' => 'income-'.$income->id,
                'date' => Carbon::parse($income->received_on)->toDateString(),
                'date_jalali' => Jalali::date($income->received_on),
                'source' => 'other',
                'source_label' => 'درآمد متفرقه',
                'amount' => round((float) $income->amount, 2),
                'note' => $income->note,
            ]);

        return $flour->concat($other)->sortBy('date')->values()->all();
    }

    private function production(Carbon $from, Carbon $to): array
    {
        return DoughEntry::with(['user:id,name', 'chaneEntries'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (DoughEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->created_at->toDateString(),
                'date_jalali' => Jalali::date($entry->created_at),
                'bag_count' => (float) $entry->bag_count,
                'yeast_type' => $entry->yeast_type,
                'status' => $entry->status,
                'dough_maker' => $entry->user?->name,
                'chane_count' => (int) $entry->chaneEntries->sum('chane_count'),
                'normal_weight_kg' => round((float) $entry->chaneEntries->sum('normal_weight_kg'), 3),
                'nanino_weight_kg' => round((float) $entry->chaneEntries->sum('nanino_weight_kg'), 3),
                'spray_flour_kg' => round((float) $entry->chaneEntries->sum('spray_flour_kg'), 3),
            ])
            ->all();
    }

    private function inventory(Carbon $from, Carbon $to): array
    {
        return InventoryMovement::with('item:id,key,name,unit')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (InventoryMovement $movement) => [
                'id' => $movement->id,
                'date' => $movement->created_at->toDateString(),
                'date_jalali' => Jalali::date($movement->created_at),
                'item' => $movement->item?->key,
                'item_label' => $movement->item?->name,
                'unit' => $movement->item?->unit,
                'direction' => $movement->direction,
                'reason' => $movement->reason,
                'reason_label' => $movement->reason_label,
                'quantity' => round((float) $movement->quantity, 3),
                // The sign a report wants to sum without a measure of its own.
                'signed_quantity' => round(
                    (float) $movement->quantity * ($movement->direction === 'out' ? -1 : 1), 3
                ),
            ])
            ->all();
    }

    private function salaries(Carbon $from, Carbon $to): array
    {
        return SalaryPayment::with('user:id,name')
            ->whereBetween('period_start', [$from->toDateString(), $to->toDateString()])
            ->orderBy('period_start')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (SalaryPayment $payment) => [
                'id' => $payment->id,
                'employee' => $payment->user?->name,
                'period_start' => Carbon::parse($payment->period_start)->toDateString(),
                'period_start_jalali' => Jalali::date($payment->period_start),
                'base_amount' => round((float) $payment->base_amount, 2),
                'bonus' => round((float) $payment->bonus, 2),
                'deductions' => round((float) $payment->deductions, 2),
                'net_amount' => round((float) $payment->net_amount, 2),
                'paid' => $payment->paid_on !== null,
                'paid_on' => $payment->paid_on
                    ? Carbon::parse($payment->paid_on)->toDateString()
                    : null,
            ])
            ->all();
    }

    /**
     * Defaults to the last year, since a report tool pulls a history rather
     * than a single day the way the app screens do.
     */
    private function range(Request $request): array
    {
        $from = Jalali::parseFlexible($request->query('from'))?->startOfDay()
            ?? now()->copy()->subYear()->startOfDay();

        $to = Jalali::parseFlexible($request->query('to'))?->endOfDay()
            ?? now()->endOfDay();

        return [$from, $to];
    }
}
