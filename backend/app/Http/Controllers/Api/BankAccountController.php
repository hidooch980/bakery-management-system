<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $accounts = BankAccount::orderByDesc('is_default')->orderBy('title')->get();

        $total = $accounts->where('is_active', true)
            ->sum(fn (BankAccount $a) => $a->balance);

        return $this->success([
            'accounts' => $accounts->map(fn (BankAccount $a) => $this->present($a))->values(),
            'total_balance' => Money::convert($total),
            'total_balance_formatted' => Money::format($total),
            'currency' => Money::currency(),
            'currency_label' => Money::label(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $account = BankAccount::create($data);

        return $this->success($this->present($account), 'حساب ثبت شد.', 201);
    }

    public function update(Request $request, BankAccount $account): JsonResponse
    {
        $account->update($this->validated($request));

        return $this->success($this->present($account->fresh()), 'حساب به‌روزرسانی شد.');
    }

    public function destroy(BankAccount $account): JsonResponse
    {
        // Deleting an account with history would silently drop the
        // transactions that explain other records' money.
        if ($account->transactions()->exists()) {
            return $this->error(
                'این حساب گردش مالی دارد و قابل حذف نیست. می‌توانید آن را غیرفعال کنید.',
                409
            );
        }

        $account->delete();

        return $this->success(null, 'حساب حذف شد.');
    }

    /** The account statement: opening balance, then every movement. */
    public function transactions(Request $request, BankAccount $account): JsonResponse
    {
        $from = Jalali::parseFlexible($request->query('from'));
        $until = Jalali::parseFlexible($request->query('until'));

        $transactions = $account->transactions()
            ->with('user:id,name')
            ->when($from, fn ($q) => $q->whereDate('occurred_on', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('occurred_on', '<=', $until))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        return $this->success([
            'account' => $this->present($account),
            'transactions' => $transactions->map(fn (BankTransaction $t) => [
                'id' => $t->id,
                'direction' => $t->direction,
                'amount' => Money::convert($t->amount),
                'amount_formatted' => $t->amount_formatted,
                'reason' => $t->reason,
                'reason_label' => $t->reason_label,
                'occurred_on' => $t->occurred_on?->toDateString(),
                'occurred_on_display' => $t->occurred_on_display,
                'user' => $t->user?->name,
                'note' => $t->note,
            ])->values(),
        ]);
    }

    /** A hand-entered deposit or withdrawal. */
    public function record(Request $request, BankAccount $account): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_on' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $transaction = $account->record(
            $data['direction'],
            Money::toToman($data['amount']),
            'manual',
            $request->user()->id,
            null,
            $data['note'] ?? null,
            Jalali::parseFlexible($data['occurred_on'] ?? null) ?? now(),
        );

        return $this->success([
            'transaction' => $transaction,
            'balance' => Money::convert($account->fresh()->balance),
            'balance_formatted' => $account->fresh()->balance_formatted,
        ], 'تراکنش ثبت شد.', 201);
    }

    /** Moves money between two accounts as a matched pair of movements. */
    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'exists:bank_accounts,id'],
            'to_account_id' => ['required', 'exists:bank_accounts,id', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_on' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $from = BankAccount::find($data['from_account_id']);
        $to = BankAccount::find($data['to_account_id']);
        $amount = Money::toToman($data['amount']);
        $on = Jalali::parseFlexible($data['occurred_on'] ?? null) ?? now();

        // Both legs succeed or neither does, so a transfer can never leave
        // money missing from one side.
        DB::transaction(function () use ($from, $to, $amount, $on, $data, $request) {
            $note = $data['note'] ?? null;

            $from->record('out', $amount, 'transfer', $request->user()->id, null,
                $note ?? "انتقال به {$to->title}", $on);
            $to->record('in', $amount, 'transfer', $request->user()->id, null,
                $note ?? "انتقال از {$from->title}", $on);
        });

        return $this->success([
            'from' => $this->present($from->fresh()),
            'to' => $this->present($to->fresh()),
        ], 'انتقال انجام شد.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'card_number' => ['nullable', 'string', 'max:30'],
            'iban' => ['nullable', 'string', 'max:34'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($data['opening_balance'])) {
            $data['opening_balance'] = Money::toToman($data['opening_balance']);
        }

        return $data;
    }

    private function present(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'title' => $account->title,
            'label' => $account->label,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'card_number' => $account->card_number,
            'iban' => $account->iban,
            'opening_balance' => Money::convert($account->opening_balance),
            'balance' => Money::convert($account->balance),
            'balance_formatted' => $account->balance_formatted,
            'is_overdrawn' => $account->is_overdrawn,
            'is_default' => $account->is_default,
            'is_active' => $account->is_active,
            'note' => $account->note,
        ];
    }
}
