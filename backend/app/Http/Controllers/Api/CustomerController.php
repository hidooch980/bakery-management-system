<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    use ApiResponse;

    /** Readable by sellers so they can attribute a sale to a school or office. */
    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->boolean('active_only', true), fn ($q) => $q->active())
            // Sellers want buyers; the consignment screen wants partners.
            ->when($request->boolean('buyers_only'), fn ($q) => $q->buyers())
            ->when($request->boolean('partners_only'), fn ($q) => $q->partners())
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'type_label' => $c->type_label,
                'contact_name' => $c->contact_name,
                'phone' => $c->phone,
                'is_active' => $c->is_active,
            ]);

        return $this->success($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Customer::TYPES))],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = Customer::create($data + ['is_active' => true]);

        return $this->success($customer, 'مشتری ثبت شد.', 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::in(array_keys(Customer::TYPES))],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $customer->update($data);

        return $this->success($customer->fresh(), 'مشتری به‌روزرسانی شد.');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return $this->success(null, 'مشتری حذف شد.');
    }

    public function types(): JsonResponse
    {
        return $this->success(
            collect(Customer::TYPES)->map(fn ($label, $value) => compact('value', 'label'))->values()
        );
    }
}
