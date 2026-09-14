<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\CustomerCredit;
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
            // Cash stays in the till; a card payment really did land in the
            // bank, so it is the only one posted to an account.
            'method' => ['nullable', 'in:cash,card'],
        ]);

        $amount = Money::toToman((float) $data['amount']);
        $method = $data['method'] ?? 'cash';

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

        // Money the shop is already holding for this buyer, from a previous
        // payment that did not land on an invoice boundary. It is spent
        // before any new money is asked for, so a school that overpaid on
        // Sunday does not pay it again on Monday.
        $credit = CustomerCredit::balanceFor($customer->id);

        if ($amount > $owed - $credit + 0.01) {
            return $this->error(sprintf(
                'مبلغ دریافتی (%s) از بدهی این مشتری (%s) بیشتر است.',
                Money::format($amount),
                Money::format(max(0, $owed - $credit)),
            ), 422);
        }

        $settled = DB::transaction(function () use ($open, $amount, $credit, $method, $request, $customer) {
            // The credit the shop is already holding pays first, so it is
            // spent rather than sitting there while the buyer keeps paying
            // whole invoices around it.
            $remaining = round($amount + $credit, 2);
            $count = 0;

            foreach ($open as $sale) {
                if ($remaining + 0.01 < (float) $sale->amount) {
                    break;
                }

                $sale->update(['settled_on' => now()]);
                $remaining -= (float) $sale->amount;
                $count++;
            }

            // The whole handover is banked, not just the part that landed
            // on an invoice boundary.
            //
            // It used to bank only what cleared an invoice and drop the
            // rest: a school paying ۲۵۰ against three invoices of ۱۰۰ had
            // ۲۰۰ recorded and ۵۰ recorded nowhere — money in the seller's
            // hand that nothing in the shop knew existed, and the seller
            // was shown «ثبت شد».
            //
            // The card share goes to the card account, as everywhere else
            // in the shop. Here alone it went to the default bank, so a
            // shop that keeps the reader on its own account had these
            // collections land in the wrong one.
            if ($amount > 0) {
                $account = $method === 'card'
                    ? BankAccount::cardAccount()
                    : BankAccount::cashBox();

                $account?->record(
                    'in',
                    $amount,
                    'settlement',
                    $request->user()->id,
                    null,
                    ($method === 'card' ? 'وصول نسیه با کارتخوان — ' : 'وصول نسیه نقدی — ')
                        .$customer->name,
                );
            }

            // What this payment and the old credit together did not close.
            // Written as the change since the balance before, so the
            // balance stays the sum of the column rather than a figure two
            // places have to keep agreeing on.
            $change = round($remaining - $credit, 2);

            if (abs($change) >= 0.01) {
                CustomerCredit::create([
                    'customer_id' => $customer->id,
                    'user_id' => $request->user()->id,
                    'amount' => $change,
                    'note' => $change > 0
                        ? 'باقی‌ماندهٔ دریافت، تا فاکتور بعدی'
                        : 'خرج شدن اعتبار در دریافت',
                ]);
            }

            return $count;
        });

        // Nothing closed is no longer a refusal. The money is recorded and
        // waiting, and this payment with the next one will close the
        // invoice between them — where refusing sent the seller away
        // holding cash the shop had no row for.
        $held = CustomerCredit::balanceFor($customer->id);

        return $this->success(
            ['settled' => $settled, 'credit' => Money::convert($held)],
            $settled === 0
                ? sprintf(
                    'دریافت از %s ثبت شد. هنوز فاکتوری کامل نشده؛ %s نزد مغازه می‌ماند و روی دریافت بعدی خرج می‌شود.',
                    $customer->name,
                    Money::format($held),
                )
                : sprintf(
                    'دریافت از %s ثبت شد (%d فاکتور تسویه شد).%s',
                    $customer->name,
                    $settled,
                    $held >= 0.01
                        ? ' '.Money::format($held).' باقی‌مانده، روی دریافت بعدی.'
                        : '',
                ),
        );
    }
}
