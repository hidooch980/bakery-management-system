<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Expense;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $expenses = Expense::with('user:id,name')
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('spent_on', '>=', Jalali::parseFlexible($d) ?? $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('spent_on', '<=', Jalali::parseFlexible($d) ?? $d))
            ->latest('spent_on')
            ->paginate(20)
            ->through(fn (Expense $e) => $this->payload($e));

        return $this->success($expenses);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'spent_on' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            // Says the money came out of the till instead. Without it the
            // shop's own account is assumed, because that is where the card
            // takings sit and where the shop pays from.
            'paid_in_cash' => ['nullable', 'boolean'],
        ]);

        $expense = Expense::create([
            'user_id' => $request->user()->id,
            'category' => $data['category'],
            'title' => $data['title'],
            // Typed in whatever unit the shop is set to, stored in Toman.
            // Without this a shop working in Rial had its costs saved ten
            // times over: the figure went in raw and came back out through
            // the display conversion, one zero longer every time it was read.
            'amount' => Money::toToman($data['amount']),
            // Accepts either a Jalali date or a Gregorian one; defaults to today.
            'spent_on' => Jalali::parseFlexible($data['spent_on'] ?? null) ?? now(),
            'note' => $data['note'] ?? null,
            // An expense recorded from the app never named an account, so it
            // never came off the bank — the panel defaulted it and the app
            // did not, and the same expense meant two different things
            // depending on where it was typed.
            'bank_account_id' => $this->accountFor($data),
        ]);

        return $this->success($this->payload($expense), 'هزینه ثبت شد.', 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            // Retired keys are accepted on an edit and refused on a create:
            // a delivery filed as an expense before purchases existed has
            // to stay correctable, and nothing new belongs there.
            'category' => ['sometimes', Rule::in(array_keys(Expense::categoryLabels()))],
            'title' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'spent_on' => ['sometimes', 'string', 'max:20'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'bank_account_id' => ['sometimes', 'nullable', 'exists:bank_accounts,id'],
        ]);

        if (isset($data['spent_on'])) {
            $data['spent_on'] = Jalali::parseFlexible($data['spent_on']) ?? $expense->spent_on;
        }

        if (isset($data['amount'])) {
            $data['amount'] = Money::toToman($data['amount']);
        }

        $expense->update($data);

        return $this->success($this->payload($expense->fresh()), 'هزینه به‌روزرسانی شد.');
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return $this->success(null, 'هزینه حذف شد.');
    }

    public function categories(): JsonResponse
    {
        return $this->success(
            collect(Expense::CATEGORIES)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values()
        );
    }

    /**
     * Which account the money left, or null when it came out of the till.
     *
     * Everything the shop takes on the reader lands in one account and
     * everything it pays goes out of the same one, so that account is the
     * assumption. Naming one overrides it; saying it was cash clears it.
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

    private function payload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'category_label' => $expense->category_label,
            'title' => $expense->title,
            // In the display unit, matching what was typed and what every
            // other endpoint returns. Handing back the stored Toman figure
            // put the wrong number into the edit form of a Rial shop.
            'amount' => Money::convert($expense->amount),
            'amount_formatted' => Money::format($expense->amount),
            'spent_on' => $expense->spent_on?->toDateString(),
            'spent_on_jalali' => $expense->spent_on_jalali,
            'note' => $expense->note,
            'user' => $expense->relationLoaded('user') ? $expense->user?->only(['id', 'name']) : null,
        ];
    }
}
