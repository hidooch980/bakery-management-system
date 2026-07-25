<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('spent_on', '>=', Jalali::parse($d) ?? $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('spent_on', '<=', Jalali::parse($d) ?? $d))
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
        ]);

        $expense = Expense::create([
            'user_id' => $request->user()->id,
            'category' => $data['category'],
            'title' => $data['title'],
            'amount' => $data['amount'],
            // Accepts either a Jalali date or a Gregorian one; defaults to today.
            'spent_on' => Jalali::parse($data['spent_on'] ?? null) ?? now(),
            'note' => $data['note'] ?? null,
        ]);

        return $this->success($this->payload($expense), 'هزینه ثبت شد.', 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', Rule::in(array_keys(Expense::CATEGORIES))],
            'title' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'spent_on' => ['sometimes', 'string', 'max:20'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (isset($data['spent_on'])) {
            $data['spent_on'] = Jalali::parse($data['spent_on']) ?? $expense->spent_on;
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

    private function payload(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'category' => $expense->category,
            'category_label' => $expense->category_label,
            'title' => $expense->title,
            'amount' => (float) $expense->amount,
            'amount_formatted' => Money::format($expense->amount),
            'spent_on' => $expense->spent_on?->toDateString(),
            'spent_on_jalali' => $expense->spent_on_jalali,
            'note' => $expense->note,
            'user' => $expense->relationLoaded('user') ? $expense->user?->only(['id', 'name']) : null,
        ];
    }
}
