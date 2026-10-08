<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** اقساط وام بانکی نانوایی، مستقل از بدهی کارکنان. */
class LoanController extends Controller
{
    use ApiResponse;

    public function index()
    {
        return $this->success(Loan::withPaid()->orderBy('title')->get()->map(fn ($loan) => [
            'id' => $loan->id,
            'title' => $loan->title,
            'lender' => $loan->lender,
            'remaining' => Money::convert($loan->remaining),
            'remaining_formatted' => $loan->remaining_formatted,
            'paid_formatted' => $loan->paid_formatted,
            'instalment_amount' => Money::convert($loan->instalment_amount),
            'instalment_formatted' => Money::format($loan->instalment_amount),
            'next_due_on' => $loan->next_due_on_display,
            'is_overdue' => $loan->is_overdue,
            'can_pay' => $loan->settled_on === null && $loan->remaining > 0,
            'payments' => $loan->payments()->with('bankAccount')->latest('paid_on')->latest('id')->limit(20)->get()->map(fn ($payment) => [
                'id' => $payment->id,
                'amount_formatted' => Money::format($payment->amount),
                'date' => AppCalendar::date($payment->paid_on),
                'account' => $payment->bankAccount?->title,
                'note' => $payment->note,
            ]),
        ]));
    }

    public function pay(Request $request, Loan $loan)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'paid_on' => ['required', 'string', 'max:20'],
            'bank_account_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $date = Jalali::parseFlexible($data['paid_on']);
        if ($date === null || $date->gt(today())) {
            throw ValidationException::withMessages(['paid_on' => ['تاریخ پرداخت باید معتبر و حداکثر امروز باشد.']]);
        }
        BankAccount::where('is_active', true)->findOrFail($data['bank_account_id']);

        return DB::transaction(function () use ($loan, $data, $date, $request) {
            $locked = Loan::lockForUpdate()->findOrFail($loan->id);
            $amount = Money::toToman($data['amount']);
            if ($locked->settled_on !== null || $amount > $locked->remaining) {
                throw ValidationException::withMessages(['amount' => ['مبلغ پرداخت از مانده وام بیشتر است یا وام تسویه شده است.']]);
            }
            $payment = LoanPayment::create([
                'loan_id' => $locked->id,
                'user_id' => $request->user()->id,
                'amount' => $amount,
                'paid_on' => $date,
                'bank_account_id' => $data['bank_account_id'],
                'note' => $data['note'] ?? null,
            ]);

            return $this->success(['id' => $payment->id], 'پرداخت قسط و گردش حساب ثبت شد.', 201);
        });
    }
}
