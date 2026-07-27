<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\AppCalendar;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the schools and offices still owe, gathered per buyer.
 *
 * The sale list already shows every unpaid line, but chasing a debt is a
 * conversation with one school about one number, not with twenty separate
 * receipts — so the lines are summed per customer and ordered by how long
 * the oldest one has been waiting.
 */
class CustomerDebtController extends Controller
{
    use ApiResponse;

    /** How long an unpaid sale may sit before it counts as overdue. */
    public const OVERDUE_DAYS = 30;

    public function index(): JsonResponse
    {
        $sales = Sale::query()
            ->outstanding()
            ->whereNotNull('customer_id')
            ->with('customer:id,name,type')
            ->get()
            ->groupBy('customer_id');

        // Kept in Toman for the total; the per-customer figures are
        // converted for display and would double-convert if summed.
        $totalToman = round((float) Sale::query()
            ->outstanding()
            ->whereNotNull('customer_id')
            ->sum('amount'), 2);

        $customers = $sales->map(function ($lines) {
            $oldest = $lines->min('created_at');
            $days = (int) $oldest->startOfDay()->diffInDays(now()->startOfDay());
            $amount = round((float) $lines->sum('amount'), 2);
            $customer = $lines->first()->customer;

            return [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'type' => $customer->type,
                'type_label' => Customer::TYPES[$customer->type] ?? $customer->type,
                'amount' => Money::convert($amount),
                'amount_formatted' => Money::format($amount),
                'bread_count' => (int) $lines->sum('bread_count'),
                'sale_count' => $lines->count(),
                'oldest_days' => $days,
                'oldest_date_display' => AppCalendar::date($oldest),
                'is_overdue' => $days >= self::OVERDUE_DAYS,
            ];
        })
            // Longest waiting first: that is the order they need chasing in.
            ->sortByDesc('oldest_days')
            ->values();

        return $this->success([
            'customers' => $customers,
            'total' => Money::convert($totalToman),
            'total_formatted' => Money::format($totalToman),
            'overdue_count' => $customers->where('is_overdue', true)->count(),
            'overdue_days' => self::OVERDUE_DAYS,
            'currency_label' => Money::label(),
        ]);
    }

    /**
     * Marks everything a customer owes as collected. Partial payment is not
     * offered here on purpose — a half-paid debt is settled sale by sale in
     * the panel, where the individual lines are visible.
     */
    public function settle(Request $request, Customer $customer): JsonResponse
    {
        $updated = Sale::query()
            ->outstanding()
            ->where('customer_id', $customer->id)
            ->update(['settled_on' => now()]);

        if ($updated === 0) {
            return $this->error('بدهی تسویه‌نشده‌ای برای این مشتری نیست.', 422);
        }

        return $this->success(
            ['settled' => $updated],
            'بدهی '.$customer->name.' تسویه شد.'
        );
    }
}
