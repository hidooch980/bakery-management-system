<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Money paid to a mill after the delivery.
 *
 * What was handed over at the door belongs to the invoice; this is the
 * round figure paid on account days later, which is how this shop actually
 * settles. Naming an invoice is allowed and not required — the supplier's
 * balance comes out the same either way, and forcing a choice would make
 * somebody invent one.
 */
class SupplierPaymentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $payments = SupplierPayment::with(['supplier:id,name', 'user:id,name', 'bankAccount:id,title'])
            ->when($request->query('supplier_id'), fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest('paid_on')
            ->latest('id')
            ->paginate(20)
            ->through(fn (SupplierPayment $p) => $this->payload($p));

        return $this->success($payments);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_id' => ['nullable', 'exists:purchases,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['nullable', 'string', 'max:20'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'paid_in_cash' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // An invoice named has to belong to the supplier being paid, or
        // the money lands against one mill's account and pays down
        // another's — and the balances would both be wrong while each
        // looked plausible on its own.
        if (! empty($data['purchase_id'])) {
            $purchase = Purchase::find($data['purchase_id']);

            if (! $purchase || (int) $purchase->supplier_id !== (int) $data['supplier_id']) {
                return $this->error('این فاکتور برای این تأمین‌کننده نیست.', 422);
            }
        }

        $payment = SupplierPayment::create([
            'supplier_id' => $data['supplier_id'],
            'purchase_id' => $data['purchase_id'] ?? null,
            'user_id' => $request->user()->id,
            // Typed in the shop's display unit, stored in Toman.
            'amount' => Money::toToman($data['amount']),
            'paid_on' => Jalali::parseFlexible($data['paid_on'] ?? null) ?? now(),
            'bank_account_id' => $this->accountFor($data),
            'note' => $data['note'] ?? null,
        ]);

        return $this->success(
            $this->payload($payment->fresh(['supplier', 'bankAccount'])),
            'پرداخت ثبت شد.',
            201
        );
    }

    public function destroy(SupplierPayment $payment): JsonResponse
    {
        // The bank movement goes with it — see PostsToBankAccount.
        $payment->delete();

        return $this->success(null, 'پرداخت حذف شد.');
    }

    /**
     * One supplier's account: what they have invoiced, what has been paid,
     * and what is left.
     */
    public function account(Supplier $supplier): JsonResponse
    {
        $supplier->load(['purchases.items.item:id,key,name', 'payments.bankAccount:id,title']);

        return $this->success([
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'kind' => $supplier->kind,
                'is_active' => $supplier->is_active,
            ],
            'balance' => Money::convert($supplier->balance),
            'balance_formatted' => $supplier->balance_formatted,
            'is_settled' => $supplier->is_settled,
            'invoiced' => Money::convert($supplier->purchases->sum('amount')),
            'paid' => Money::convert(
                $supplier->purchases->sum('paid_amount') + $supplier->payments->sum('amount')
            ),
            'purchases' => $supplier->purchases
                ->sortByDesc('purchased_on')
                ->values()
                ->map(fn (Purchase $p) => [
                    'id' => $p->id,
                    'invoice_no' => $p->invoice_no,
                    'purchased_on_display' => AppCalendar::date($p->purchased_on),
                    'amount' => Money::convert($p->amount),
                    'amount_formatted' => $p->amount_formatted,
                    'paid_amount' => Money::convert($p->paid_amount),
                    'lines' => $p->items->map(fn ($line) => $line->label.' — '.$line->quantity_label)->values(),
                ]),
            'payments' => $supplier->payments
                ->sortByDesc('paid_on')
                ->values()
                ->map(fn (SupplierPayment $p) => $this->payload($p)),
            'currency_label' => Money::label(),
        ]);
    }

    /**
     * The same rule as an expense and a purchase: the shop's own account
     * is the assumption, naming one overrides it, saying it was cash
     * clears it.
     *
     * @param  array<string, mixed>  $data
     */
    private function accountFor(array $data): ?int
    {
        if (array_key_exists('bank_account_id', $data) && $data['bank_account_id'] !== null) {
            return (int) $data['bank_account_id'];
        }

        if (filter_var($data['paid_in_cash'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return BankAccount::defaultAccount()?->id;
    }

    private function payload(SupplierPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'supplier_id' => $payment->supplier_id,
            'supplier_name' => $payment->supplier?->name,
            'purchase_id' => $payment->purchase_id,
            'amount' => Money::convert($payment->amount),
            'amount_formatted' => $payment->amount_formatted,
            'paid_on' => $payment->paid_on?->toDateString(),
            'paid_on_display' => AppCalendar::date($payment->paid_on),
            'account' => $payment->bankAccount?->title,
            'note' => $payment->note,
            'user' => $payment->relationLoaded('user') ? $payment->user?->only(['id', 'name']) : null,
        ];
    }
}
