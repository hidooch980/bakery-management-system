<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bakery extends Model
{
    protected $fillable = [
        'name',
        'address',
        'phone',
        'logo',
        'description',
    ];
}
