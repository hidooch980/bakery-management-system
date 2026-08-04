<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Models\Loan;
use App\Support\BalanceSheet;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * What the shop owns against what it owes.
 */
class BalanceSheetController extends Controller
{
    use ApiResponse;

    public function show(): JsonResponse
    {
        return $this->success([
            ...BalanceSheet::build(),
            // Named alongside the totals, because "دارایی ثابت: ۰" is a
            // question — is there no oven, or has nobody written it down?
            'fixed_assets' => FixedAsset::held()->orderByDesc('id')->get()
                ->map(fn (FixedAsset $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'category_label' => $a->category_label,
                    'value_formatted' => $a->value_formatted,
                    'purchased_on_display' => $a->purchased_on_display,
                ])->values(),
            'loans' => Loan::outstanding()->orderBy('first_due_on')->get()
                ->map(fn (Loan $l) => [
                    'id' => $l->id,
                    'title' => $l->title,
                    'lender' => $l->lender,
                    'remaining_formatted' => $l->remaining_formatted,
                    'paid_formatted' => $l->paid_formatted,
                    'progress_percent' => $l->progress_percent,
                    'next_due_on_display' => $l->next_due_on_display,
                    'is_overdue' => $l->is_overdue,
                ])->values(),
        ]);
    }
}
