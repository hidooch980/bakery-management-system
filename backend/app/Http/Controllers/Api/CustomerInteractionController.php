<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Support\AppCalendar;
use App\Support\Jalali;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The record of dealing with a customer, and what was promised next.
 *
 * A sale says what they bought. This says what was said about it — the
 * call that agreed a payment date, the complaint that is still open —
 * so it does not live in one person's memory.
 */
class CustomerInteractionController extends Controller
{
    use ApiResponse;

    /** Everything said to one customer, newest first. */
    public function index(Customer $customer): JsonResponse
    {
        return $this->success([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'type_label' => $customer->type_label,
                'phone' => $customer->phone,
                'contact_name' => $customer->contact_name,
                'outstanding_formatted' => Money::format($customer->outstanding),
            ],
            'interactions' => $customer->interactions()->with('user:id,name')
                ->limit(50)->get()
                ->map(fn (CustomerInteraction $i) => $this->present($i))
                ->values(),
        ]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(CustomerInteraction::TYPES))],
            'summary' => ['required', 'string', 'max:1000'],
            'follow_up_on' => ['nullable', 'string'],
        ]);

        $interaction = $customer->interactions()->create([
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'summary' => $data['summary'],
            // The app speaks Jalali; the column stores Gregorian.
            'follow_up_on' => empty($data['follow_up_on'])
                ? null
                : Jalali::parse($data['follow_up_on']),
        ]);

        return $this->success($this->present($interaction->load('user:id,name')), 'ثبت شد.', 201);
    }

    /** Marks a follow-up as done, so it drops off the call list. */
    public function complete(CustomerInteraction $interaction): JsonResponse
    {
        if (! $interaction->is_open) {
            return $this->error('این پیگیری باز نیست.', 422);
        }

        $interaction->update(['completed_at' => now()]);

        return $this->success(null, 'پیگیری انجام شد.');
    }

    /**
     * Today's call list: every follow-up that has come due, oldest first,
     * with what the customer owes so the caller has it to hand.
     */
    public function dueFollowUps(): JsonResponse
    {
        $due = CustomerInteraction::query()
            ->due()
            ->with(['customer:id,name,phone,type', 'user:id,name'])
            ->orderBy('follow_up_on')
            ->get();

        return $this->success([
            'follow_ups' => $due->map(fn (CustomerInteraction $i) => [
                ...$this->present($i),
                'customer_id' => $i->customer_id,
                'customer_name' => $i->customer?->name,
                'customer_phone' => $i->customer?->phone,
                'outstanding_formatted' => Money::format($i->customer?->outstanding ?? 0),
            ])->values(),
            'count' => $due->count(),
        ]);
    }

    private function present(CustomerInteraction $interaction): array
    {
        return [
            'id' => $interaction->id,
            'type' => $interaction->type,
            'type_label' => $interaction->type_label,
            'summary' => $interaction->summary,
            'by' => $interaction->user?->name,
            'date_display' => AppCalendar::date($interaction->created_at),
            'follow_up_display' => $interaction->follow_up_on
                ? AppCalendar::date($interaction->follow_up_on)
                : null,
            'is_open' => $interaction->is_open,
            'is_overdue' => $interaction->is_overdue,
        ];
    }
}
