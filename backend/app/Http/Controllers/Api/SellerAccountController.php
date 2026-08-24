<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Exclusively;
use App\Support\Money;
use App\Support\SellerSettlement;
use App\Support\SameBakery;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What every seller owes, for the admin's app.
 *
 * The panel already shows this, but the admin is usually on the shop floor
 * with a phone rather than at the desk, and settling is the moment the
 * money actually changes hands — so it belongs where they are standing.
 */
class SellerAccountController extends Controller
{
    use ApiResponse;

    /** Every seller with something still open, plus their pending request. */
    public function index(): JsonResponse
    {
        $pending = SettlementRequest::pending()->get()->keyBy('user_id');

        $sellers = User::ofCurrentBakery()->role('seller')->orderBy('name')->get()
            ->map(function (User $seller) use ($pending) {
                $owed = SellerSettlement::outstandingFor($seller);
                $request = $pending->get($seller->id);

                return [
                    'id' => $seller->id,
                    'name' => $seller->name,
                    'cash' => Money::convert($owed['cash']),
                    'cash_formatted' => Money::format($owed['cash']),
                    'difference_formatted' => Money::format($owed['difference']),
                    'shortfall_formatted' => Money::format($owed['shortfall']),
                    'credit' => Money::convert($owed['credit']),
                    'credit_formatted' => Money::format($owed['credit']),
                    'settleable' => Money::convert($owed['total']),
                    'settleable_formatted' => Money::format($owed['total']),
                    'request' => $request ? [
                        'id' => $request->id,
                        'amount_formatted' => $request->amount_formatted,
                        'paid_cash_formatted' => Money::format((float) $request->paid_cash),
                        'paid_card_formatted' => Money::format((float) $request->paid_card),
                        'note' => $request->note,
                        'requested_on_display' => AppCalendar::date($request->created_at),
                    ] : null,
                ];
            })
            // A seller who owes nothing and has asked for nothing is not
            // something the admin needs to scroll past.
            ->filter(fn (array $s) => $s['settleable'] > 0
                || $s['credit'] > 0
                || $s['request'] !== null)
            ->values();

        return $this->success([
            'sellers' => $sellers,
            'pending_count' => $sellers->whereNotNull('request')->count(),
            'currency_label' => Money::label(),
        ]);
    }

    /**
     * Confirms a seller's request. The card share has already reached the
     * bank on its own, so it is posted to the account rather than counted
     * as cash the admin took by hand.
     */
    public function confirm(Request $request, SettlementRequest $settlement): JsonResponse
    {
        // Validated before the lock is taken: it reads nothing about the
        // settlement, and there is no reason to hold a row while deciding
        // whether the caller typed a real account id.
        $data = $request->validate([
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
        ]);

        // The most expensive race in this file. Confirming posts the cash
        // to a bank account; two admins tapping confirm a moment apart
        // both saw `pending` and would both post it, and the shop's
        // balance would carry a seller's takings twice.
        Exclusively::claim(
            $settlement,
            fn (SettlementRequest $s) => $s->is_pending
                ? null
                : 'این درخواست قبلاً بررسی شده است.',
            fn (SettlementRequest $s) => SellerSettlement::confirm(
                $s,
                $request->user(),
                isset($data['bank_account_id'])
                    ? BankAccount::find($data['bank_account_id'])
                    : null,
            ),
        );

        return $this->success(null, 'تسویه تأیید شد.');
    }

    public function reject(Request $request, SettlementRequest $settlement): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Confirm and reject race each other too, not just themselves: one
        // admin approving while another turns it down leaves whichever
        // wrote last, and the money may already have moved.
        Exclusively::claim(
            $settlement,
            fn (SettlementRequest $s) => $s->is_pending
                ? null
                : 'این درخواست قبلاً بررسی شده است.',
            fn (SettlementRequest $s) => $s->update([
                'rejected_at' => now(),
                'rejection_reason' => $data['reason'],
                'confirmed_by' => $request->user()->id,
            ]),
        );

        return $this->success(null, 'درخواست رد شد.');
    }

    /**
     * Settles a seller directly, for when they hand the money over without
     * having sent a request from their own app.
     */
    /**
     * Settles a number of loaves rather than the whole account.
     *
     * The shop counts this debt in bread, so a part settlement is "three
     * hundred loaves" rather than an amount somebody has already done the
     * arithmetic on — and doing it here means the arithmetic is done once,
     * with the price the system holds.
     */
    public function settleLoaves(Request $request, User $seller): JsonResponse
    {
        $seller = SameBakery::or404($seller);

        $data = $request->validate([
            'loaves' => ['required', 'integer', 'min:1'],
        ]);

        $owed = SellerSettlement::outstandingFor($seller);

        if ($owed['loaves'] <= 0) {
            return $this->error('نانی برای تسویه وجود ندارد.', 422);
        }

        if ($data['loaves'] > $owed['loaves']) {
            return $this->error(
                'بیش از بدهی است: '.number_format($owed['loaves']).' نان بدهکار است.',
                422,
            );
        }

        $result = SellerSettlement::applyLoaves($seller, $data['loaves']);
        $left = SellerSettlement::outstandingFor($seller);

        return $this->success([
            'settled_sales' => count($result['settled']),
            'credit_left' => Money::convert($result['credit_left']),
            'loaves_left' => $left['loaves'],
            'total_left' => Money::convert($left['total']),
            'total_left_formatted' => Money::format($left['total']),
        ], number_format($data['loaves']).' نان از حساب '.$seller->name.' تسویه شد.');
    }

    public function settle(Request $request, User $seller): JsonResponse
    {
        $seller = SameBakery::or404($seller);

        $owed = SellerSettlement::outstandingFor($seller);

        if ($owed['total'] <= 0) {
            return $this->error('مبلغی برای تسویه وجود ندارد.', 422);
        }

        SellerSettlement::settle($seller);

        return $this->success(null, 'حساب '.$seller->name.' تسویه شد.');
    }
}
