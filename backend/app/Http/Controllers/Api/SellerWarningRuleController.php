<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerWarningRule;
use App\Support\Money;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The manager's seller-warning rules: read, switch on or off, set a threshold. */
class SellerWarningRuleController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        SellerWarningRule::ensureDefaults();

        return $this->success([
            'rules' => SellerWarningRule::query()->orderBy('id')->get()->map(fn ($r) => self::payload($r))->values(),
        ]);
    }

    public function update(Request $request, SellerWarningRule $rule): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('threshold', $data) && $data['threshold'] !== null && $rule->isMoney()) {
            $data['threshold'] = Money::toToman($data['threshold']);
        }

        $threshold = array_key_exists('threshold', $data) ? $data['threshold'] : $rule->threshold;
        if (($data['is_active'] ?? false) && ($threshold === null || (float) $threshold <= 0)) {
            return $this->error('اول مقدار این قانون را وارد کنید، بعد روشنش کنید.', 422);
        }

        $rule->update($data);

        return $this->success(self::payload($rule->fresh()), 'قانون ذخیره شد.');
    }

    public static function payload(SellerWarningRule $r): array
    {
        $t = $r->threshold === null ? null : (float) $r->threshold;

        return [
            'id' => $r->id,
            'name' => $r->name,
            'condition' => $r->condition,
            'condition_label' => $r->condition_label,
            'unit' => $r->unit,
            'unit_label' => $r->isMoney() ? Money::label() : SellerWarningRule::UNIT_LABELS[$r->unit],
            'threshold' => $t === null ? null : ($r->isMoney() ? Money::convert($t) : $t),
            'threshold_label' => $t === null ? 'تعیین نشده' : ($r->isMoney() ? Money::format($t)
                : rtrim(rtrim(number_format($t, 2, '.', ''), '0'), '.').' '.SellerWarningRule::UNIT_LABELS[$r->unit]),
            'description' => $r->description,
            'source' => $r->source,
            'source_url' => $r->source_url,
            'is_active' => (bool) $r->is_active,
        ];
    }
}
