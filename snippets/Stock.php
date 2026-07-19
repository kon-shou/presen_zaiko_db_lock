<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// app/Models/Stock.php
class Stock extends Model
{
    protected $fillable = ['sku', 'quantity'];

    // quantity は整数として扱う
    protected $casts = [
        'quantity' => 'integer',
    ];
}
