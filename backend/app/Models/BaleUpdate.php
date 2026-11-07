<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBakery;
use Illuminate\Database\Eloquent\Model;

/** هر آپدیت بلهٔ دریافت‌شده، تا تکرارش دوباره پردازش نشود. */
class BaleUpdate extends Model
{
    use BelongsToBakery;

    protected $fillable = ['bakery_id', 'update_id', 'outcome'];
}
