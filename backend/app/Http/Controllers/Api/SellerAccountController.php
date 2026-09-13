<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\SettlementRequest;
use App\Models\User;
use App\Support\AppCalendar;
use App\Support\Exclusively;
use App\Support\Money;
use App\Support\SameBakery;
use App\Support\SellerSettlement;
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

        $people = User::ofCurrentBakery()->role('seller')->orderBy('name')->get();

        // Every seller's open sales in one query. Asked per seller, this
        // page put a query on it for each person who has ever sold bread.
        $owedBySeller = SellerSettlement::outstandingForMany($people);

        $sellers = $people
            ->map(function (User $seller) use ($pending, $owedBySeller) {
                $owed = $owedBySeller[$seller->id];
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

        $data = $request->validate([
            'paid_cash' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'paid_card' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'bank_account_id' => ['sometimes', 'nullable', 'exists:bank_accounts,id'],
        ]);

        // This used to close the account and move no money whatever — the
        // seller handed over the day's takings, the row went green, and no
        // account anywhere heard of it. The panel at least asked for the
        // split; the phone, which is what the owner actually carries, asked
        // for nothing and recorded nothing.
        //
        // Silence now means notes. A seller settling up at the counter is
        // handing over cash, so that is what is assumed when the caller
        // says nothing — and an older copy of the app, which sends neither
        // field, starts recording the money instead of losing it.
        $card = isset($data['paid_card']) ? Money::toToman((float) $data['paid_card']) : 0.0;
        $cash = isset($data['paid_cash'])
            ? Money::toToman((float) $data['paid_cash'])
            : round(max(0, $owed['total'] - $card), 2);

        if (round($cash + $card, 2) > round($owed['total'], 2) + 0.01) {
            return $this->error(
                'جمع نقد و کارتخوان از بدهی حساب بیشتر است: '
                    .Money::format($owed['total']).' بدهکار است.',
                422,
            );
        }

        $named = isset($data['bank_account_id'])
            ? BankAccount::find($data['bank_account_id'])
            : null;

        // Less than the account owes is a payment, not a settlement. This
        // closed the whole account for whatever was handed over, so a
        // seller who gave back a tenth of what he owed had the rest
        // written off by the app — the one path in the system where
        // handing over less money made the debt smaller than the money.
        $partial = round($cash + $card, 2) < round($owed['total'], 2) - 0.01;

        $account = $partial
            ? SellerSettlement::payWithMethod($seller, $request->user(), $cash, $card, $named)
            : SellerSettlement::settleWithMethod($seller, $request->user(), $cash, $card, $named);

        return $this->success([
            'settled' => ! $partial,
            'left' => Money::convert(
                round(max(0, SellerSettlement::outstandingFor($seller)['total']), 2)
            ),
            'cash' => Money::convert($cash),
            'card' => Money::convert($card),
            'account' => $account?->title,
        ], $partial
            ? Money::format(round($cash + $card, 2)).' به حساب '.$seller->name.' واریز شد.'
            : 'حساب '.$seller->name.' تسویه شد.');
    }
}
