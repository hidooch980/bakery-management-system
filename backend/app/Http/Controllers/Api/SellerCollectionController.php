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
use Illuminate\Support\Facades\DB;

/**
 * What the schools, offices and dormitories owe this seller, and the money
 * they have handed back.
 *
 * The seller is the one who delivers to them and the one they pay, so the
 * account belongs on the seller's own screen rather than only the admin's
 * — otherwise they collect without knowing the balance, or chase a debt
 * that was already settled.
 */
class SellerCollectionController extends Controller
{
    use ApiResponse;

    /** The buyers who run an account, as opposed to walk-in trade. */
    public const ACCOUNT_TYPES = ['school', 'office', 'dormitory'];

    public function index(Request $request): JsonResponse
    {
        $sales = Sale::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('payment_type', Sale::DEBT_TYPES)
            ->whereHas('customer', fn ($q) => $q->whereIn('type', self::ACCOUNT_TYPES))
            ->with('customer:id,name,type')
            ->get()
            ->groupBy('customer_id');

        $customers = $sales->map(function ($lines) {
            $customer = $lines->first()->customer;
            $open = $lines->whereNull('settled_on');
            $owed = round((float) $open->sum('amount'), 2);
            $collected = round((float) $lines->whereNotNull('settled_on')->sum('amount'), 2);

            return [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'type_label' => $customer->type_label,
                'owed' => Money::convert($owed),
                'owed_formatted' => Money::format($owed),
                // What has already come back, so the seller can see the
                // account moving rather than only what is left.
                'collected_formatted' => Money::format($collected),
                'open_count' => $open->count(),
                'oldest_display' => $open->isEmpty()
                    ? null
                    : AppCalendar::date($open->min('created_at')),
            ];
        })
            ->sortByDesc('owed')
            ->values();

        $total = round((float) $sales->flatten()->whereNull('settled_on')->sum('amount'), 2);

        return $this->success([
            'customers' => $customers,
            'total' => Money::convert($total),
            'total_formatted' => Money::format($total),
            'currency_label' => Money::label(),
        ]);
    }

    /**
     * Records money a buyer handed back.
     *
     * Paid oldest first, which is how the shop talks about it: a school
     * that pays "one invoice" means the one that has been waiting longest.
     * A part payment clears what it covers and leaves the rest open.
     */
    public function collect(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $amount = Money::toToman((float) $data['amount']);

        $open = Sale::query()
            ->where('user_id', $request->user()->id)
            ->where('customer_id', $customer->id)
            ->outstanding()
            ->orderBy('created_at')
            ->get();

        if ($open->isEmpty()) {
            return $this->error('بدهی تسویه‌نشده‌ای برای این مشتری ثبت نشده است.', 422);
        }

        $owed = round((float) $open->sum('amount'), 2);

        if ($amount > $owed + 0.01) {
            return $this->error(sprintf(
                'مبلغ دریافتی (%s) از بدهی این مشتری (%s) بیشتر است.',
                Money::format($amount),
                Money::format($owed),
            ), 422);
        }

        $settled = DB::transaction(function () use ($open, $amount) {
            $remaining = $amount;
            $count = 0;

            foreach ($open as $sale) {
                if ($remaining + 0.01 < (float) $sale->amount) {
                    break;
                }

                $sale->update(['settled_on' => now()]);
                $remaining -= (float) $sale->amount;
                $count++;
            }

            return $count;
        });

        if ($settled === 0) {
            return $this->error(
                'مبلغ دریافتی از قدیمی‌ترین فاکتور این مشتری کمتر است. پرداخت جزئی در پنل ثبت می‌شود.',
                422
            );
        }

        return $this->success(
            ['settled' => $settled],
            sprintf('دریافت از %s ثبت شد (%d فاکتور تسویه شد).', $customer->name, $settled)
        );
    }
}
