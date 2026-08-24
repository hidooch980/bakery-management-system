<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\User;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\SameBakery;
use App\Support\SellerSettlement;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What each seller actually did, rather than only what they still owe.
 *
 * The admin app had one seller surface: SellerDebtsSection, which lists
 * what is outstanding and filters out anybody at zero. So the seller who
 * sells well and hands the money over the same day was invisible in it,
 * and the only seller the owner ever saw was one who was behind. There is
 * no way to read that as anything but "sellers are a debt problem".
 *
 * `by_seller` has been in reports/sales since the beginning — name, count
 * and amount — and nothing has ever displayed it. Not the app, not the
 * panel. This is that question asked properly, and answered where the
 * owner is standing.
 */
class SellerPerformanceController extends Controller
{
    use ApiResponse;

    /** Every seller over the period, busiest first. */
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $sales = Sale::whereBetween('created_at', [$from, $to])->get();

        $sellers = User::ofCurrentBakery()->role('seller')->orderBy('name')->get()
            ->map(fn (User $seller) => $this->summarise(
                $seller,
                $sales->where('user_id', $seller->id),
            ))
            // Ordered by what they sold, not by name. The question this
            // answers is "who is carrying the shop", and a name is not an
            // answer to it.
            ->sortByDesc('bread_count')
            ->values();

        return $this->success([
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency_label' => Money::label(),
            'sellers' => $sellers,
            'totals' => [
                'bread_count' => (int) $sales->sum('bread_count'),
                'amount_formatted' => Money::format($sales->sum('amount')),
                'shortfall_formatted' => Money::format($sales->sum('shortfall_amount')),
            ],
        ]);
    }

    /** One seller, sale by sale. */
    public function show(Request $request, User $seller): JsonResponse
    {
        $seller = SameBakery::or404($seller);

        [$from, $to] = $this->range($request);

        $sales = Sale::with('customer:id,name')
            ->where('user_id', $seller->id)
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'seller' => ['id' => $seller->id, 'name' => $seller->name],
            'from_jalali' => Jalali::date($from),
            'to_jalali' => Jalali::date($to),
            'currency_label' => Money::label(),
            'summary' => $this->summarise($seller, $sales),
            // Grouped by day, because that is how the shop remembers a
            // week, and ninety flat lines is not something anybody reads
            // on a phone.
            'days' => $sales
                ->groupBy(fn (Sale $sale) => $sale->created_at->toDateString())
                ->map(fn (Collection $ofDay, string $date) => [
                    'date_jalali' => Jalali::date($date),
                    'date_long' => Jalali::longDate($date),
                    'bread_count' => (int) $ofDay->sum('bread_count'),
                    'amount_formatted' => Money::format($ofDay->sum('amount')),
                    'lines' => $ofDay->map(fn (Sale $sale) => [
                        'id' => $sale->id,
                        'at' => $sale->created_at->format('H:i'),
                        'payment_type' => $sale->payment_type,
                        'payment_label' => Sale::PAYMENT_LABELS[$sale->payment_type]
                            ?? $sale->payment_type,
                        'bread_count' => (int) $sale->bread_count,
                        'amount_formatted' => Money::format($sale->amount),
                        'customer' => $sale->customer?->name,
                        'shortfall_count' => (int) $sale->shortfall_count,
                        'shortfall_formatted' => (float) $sale->shortfall_amount > 0
                            ? Money::format($sale->shortfall_amount)
                            : null,
                        'settled' => $sale->settled_on !== null,
                        'note' => $sale->note,
                    ])->values(),
                ])
                ->values(),
        ]);
    }

    /** @param  Collection<int, Sale>  $sales */
    private function summarise(User $seller, Collection $sales): array
    {
        return [
            'id' => $seller->id,
            'name' => $seller->name,
            'sale_count' => $sales->count(),
            'bread_count' => (int) $sales->sum('bread_count'),
            'amount' => round((float) $sales->sum('amount'), 2),
            'amount_formatted' => Money::format($sales->sum('amount')),

            // Days he actually sold on, not days in the period. Dividing
            // by the period would punish a man for the shop being shut and
            // for his own days off alike.
            'days_active' => $sales
                ->groupBy(fn (Sale $sale) => $sale->created_at->toDateString())
                ->count(),

            'by_payment_type' => $sales
                ->groupBy('payment_type')
                ->map(fn (Collection $group, string $type) => [
                    'payment_type' => $type,
                    'label' => Sale::PAYMENT_LABELS[$type] ?? $type,
                    'bread_count' => (int) $group->sum('bread_count'),
                    'amount_formatted' => Money::format($group->sum('amount')),
                ])
                ->sortByDesc('bread_count')
                ->values(),

            // Kept apart from the takings on purpose. Bread that left with
            // no money behind it is a different fact from a quiet week,
            // and folding the two together hides it.
            'shortfall_count' => (int) $sales->sum('shortfall_count'),
            'shortfall_formatted' => Money::format($sales->sum('shortfall_amount')),

            // What is in his pocket right now, and deliberately not
            // period-scoped: a debt does not belong to the week it was run
            // up in.
            //
            // Credit is reported separately because SellerSettlement keeps
            // it separate, and it is right to: `total` is what he can hand
            // over today, while credit is money a customer owes and he has
            // not got. Showing only the total made bread he had let out on
            // trust invisible, which is the sort of thing that surfaces
            // months later as a figure nobody can place.
            ...$this->debts($seller),
        ];
    }

    /**
     * What the seller is holding, split the way the shop splits it.
     *
     * @return array<string, string>
     */
    private function debts(User $seller): array
    {
        $owed = SellerSettlement::outstandingFor($seller);

        // The numbers travel beside the formatted strings so the app can
        // decide whether a line is worth drawing without parsing money
        // back out of text it just asked the server to format.
        return [
            'outstanding' => $owed['total'],
            'outstanding_formatted' => Money::format($owed['total']),
            'credit_out' => $owed['credit'],
            'credit_out_formatted' => Money::format($owed['credit']),
        ];
    }

    /** @return array<int, Carbon> */
    private function range(Request $request): array
    {
        // The quota period rather than the calendar month: that is the
        // window this shop counts in, and the owner said so.
        [$defaultFrom, $defaultTo] = Jalali::currentQuotaPeriod();

        return [
            Jalali::parseFlexible($request->query('from'))?->startOfDay() ?? $defaultFrom,
            Jalali::parseFlexible($request->query('to'))?->endOfDay() ?? $defaultTo,
        ];
    }
}
