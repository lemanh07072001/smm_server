<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'status' => 'integer',
        'sort_order' => 'integer',
    ];
}
