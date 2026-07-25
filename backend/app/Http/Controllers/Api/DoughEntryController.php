<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DoughEntry;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoughEntryController extends Controller
{
    use ApiResponse;

    /**
     * Dough maker records how many flour bags were kneaded.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bag_count' => ['required', 'integer', 'min:1', 'max:1000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = DoughEntry::create([
            'user_id' => $request->user()->id,
            'bag_count' => $data['bag_count'],
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ]);

        return $this->success($entry, 'ثبت خمیر انجام شد.', 201);
    }

    /**
     * The dough maker's own submission history.
     */
    public function myHistory(Request $request): JsonResponse
    {
        $entries = DoughEntry::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return $this->success($entries);
    }

    /**
     * Dough batches not yet turned into chane — the chane gir's work queue.
     */
    public function pending(): JsonResponse
    {
        $entries = DoughEntry::pending()
            ->with('user:id,name')
            ->latest()
            ->paginate(20);

        return $this->success($entries);
    }
}
