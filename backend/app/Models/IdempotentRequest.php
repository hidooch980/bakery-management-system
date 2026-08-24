<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A write the server has already done, remembered by the name the client
 * gave it. See the migration for why this exists.
 */
class IdempotentRequest extends Model
{
    protected $fillable = [
        'idempotency_key',
        'user_id',
        'method',
        'path',
        'body_hash',
        'status_code',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'status_code' => 'integer',
        ];
    }

    /**
     * A stable fingerprint of what was asked for.
     *
     * Sorted, because two encodings of the same body must hash alike or an
     * honest retry looks like a different request. Recursive, because the
     * sale bodies this guards carry nested lines.
     */
    public static function hashBody(array $body): string
    {
        $sort = function (array $value) use (&$sort): array {
            ksort($value);

            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($body), JSON_UNESCAPED_UNICODE));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
