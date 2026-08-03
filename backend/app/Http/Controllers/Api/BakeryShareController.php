<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BakeryShare;
use App\Models\ShareSettlement;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Ledger;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner shares ("دنگ") and the profit split between them.
 */
class BakeryShareController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $shares = BakeryShare::with('user:id,name')->orderByDesc('dang')->get();

        return $this->success([
            'shares' => $shares->map(fn (BakeryShare $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'user_id' => $s->user_id,
                'phone' => $s->phone,
                'dang' => (float) $s->dang,
                'dang_label' => $s->dang_label,
                'share_percent' => $s->share_percent,
                'is_active' => $s->is_active,
                'note' => $s->note,
            ])->values(),
            'total_dang' => BakeryShare::totalDang(),
            'full_dang' => BakeryShare::FULL_DANG,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $share = BakeryShare::create($this->validated($request));

        return $this->success($share, 'شریک ثبت شد.', 201);
    }

    public function update(Request $request, BakeryShare $share): JsonResponse
    {
        $share->update($this->validated($request));

        return $this->success($share->fresh(), 'اطلاعات شریک به‌روزرسانی شد.');
    }

    public function destroy(BakeryShare $share): JsonResponse
    {
        $share->delete();

        return $this->success(null, 'شریک حذف شد.');
    }

    /**
     * The profit split for a period, defaulting to the current Jalali month.
     */
    public function split(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $split = BakeryShare::splitFor($from, $to);

        return $this->success(array_merge($split, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_display' => AppCalendar::date($from),
            'to_display' => AppCalendar::date($to),
            'period_label' => AppCalendar::monthLabel($from),
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
        ]));
    }

    /**
     * Records a payout to one partner. The amount is snapshotted rather
     * than recomputed later, so correcting the books does not rewrite it.
     */
    public function settle(Request $request, BakeryShare $share): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'paid_on' => ['nullable', 'string'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        [$from, $to] = $this->range($request);

        // Settling the same stretch twice pays a partner twice and posts
        // the money out of the bank account twice, so an overlap with a
        // settlement already on file has to be refused. Salary payments
        // and consignment flour are guarded the same way.
        $clash = ShareSettlement::where('bakery_share_id', $share->id)
            ->where('period_start', '<=', $to->toDateString())
            ->where('period_end', '>=', $from->toDateString())
            ->first();

        if ($clash) {
            return $this->error(
                'برای این بازه قبلاً تسویه ثبت شده است ('
                .$clash->period_label.' — '.$clash->amount_formatted.').',
                409
            );
        }

        $amount = isset($data['amount'])
            ? Money::toToman($data['amount'])
            : $share->profitShare($from, $to);

        $settlement = ShareSettlement::create([
            'bakery_share_id' => $share->id,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'period_profit' => Ledger::profit($from, $to),
            'dang' => $share->dang,
            'amount' => $amount,
            'paid_on' => Jalali::parseFlexible($data['paid_on'] ?? null) ?? now(),
            'note' => $data['note'] ?? null,
        ]);

        return $this->success([
            'settlement' => $settlement,
            'amount_formatted' => $settlement->amount_formatted,
        ], 'تسویه شریک ثبت شد.', 201);
    }

    public function settlements(Request $request): JsonResponse
    {
        $settlements = ShareSettlement::with('share:id,name')
            ->when($request->query('share_id'), fn ($q, $id) => $q->where('bakery_share_id', $id))
            ->latest('period_start')
            ->limit(200)
            ->get();

        return $this->success($settlements->map(fn (ShareSettlement $s) => array_merge($s->toArray(), [
            'amount' => Money::convert($s->amount),
            'amount_formatted' => $s->amount_formatted,
            'paid_on_display' => $s->paid_on_display,
            'is_paid' => $s->is_paid,
        ]))->values());
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'exists:users,id'],
            'phone' => ['nullable', 'string', 'max:20'],
            'dang' => ['required', 'numeric', 'min:0.001', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** Defaults to the current Jalali month when no range is given. */
    private function range(Request $request): array
    {
        $from = Jalali::parseFlexible($request->query('from'));
        $until = Jalali::parseFlexible($request->query('until'));

        if ($from && $until) {
            return [$from->startOfDay(), $until->endOfDay()];
        }

        [$start, $end] = Jalali::currentMonthRange();

        return [$start, $end];
    }
}
