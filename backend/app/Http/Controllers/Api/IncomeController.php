<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Income;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $from = Jalali::parseFlexible($request->query('from'));
        $until = Jalali::parseFlexible($request->query('until'));

        $incomes = Income::query()
            ->with(['user:id,name', 'customer:id,name'])
            ->when($from, fn ($q) => $q->whereDate('received_on', '>=', $from))
            ->when($until, fn ($q) => $q->whereDate('received_on', '<=', $until))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->latest('received_on')
            ->get();

        return $this->success([
            'incomes' => $incomes->map(fn ($i) => $this->present($i))->values(),
            'summary' => [
                'count' => $incomes->count(),
                'total' => Money::convert($incomes->sum('amount')),
                'total_formatted' => Money::format($incomes->sum('amount')),
                'by_category' => $incomes->groupBy('category')->map(fn ($g) => [
                    'label' => Income::CATEGORIES[$g->first()->category] ?? '',
                    'count' => $g->count(),
                    'total' => Money::convert($g->sum('amount')),
                ]),
                'currency' => Money::currency(),
                'currency_label' => Money::label(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $income = Income::create($data + ['user_id' => $request->user()->id]);

        return $this->success($this->present($income), 'درآمد ثبت شد.', 201);
    }

    public function update(Request $request, Income $income): JsonResponse
    {
        $income->update($this->validated($request));

        return $this->success($this->present($income->fresh()), 'درآمد به‌روزرسانی شد.');
    }

    public function destroy(Income $income): JsonResponse
    {
        $income->delete();

        return $this->success(null, 'درآمد حذف شد.');
    }

    public function categories(): JsonResponse
    {
        return $this->success(collect(Income::CATEGORIES)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
            ->values());
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(Income::CATEGORIES))],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'received_on' => ['nullable', 'string'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // Amounts arrive in the display unit and are stored as Toman.
        $data['amount'] = Money::toToman($data['amount']);

        $data['received_on'] = Jalali::parseFlexible($data['received_on'] ?? null)
            ?? now();

        return $data;
    }

    private function present(Income $income): array
    {
        return array_merge($income->toArray(), [
            'amount' => Money::convert($income->amount),
            'amount_formatted' => $income->amount_formatted,
            'category_label' => $income->category_label,
            'received_on_display' => $income->received_on_display,
        ]);
    }
}
