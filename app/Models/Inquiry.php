<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'trip_type',
        'pickup_location', 'drop_location',
        'pickup_date', 'return_date',
        'passengers', 'vehicle_type', 'notes', 'status',
    ];

    protected $casts = [
        'pickup_date' => 'date',
        'return_date' => 'date',
    ];
}
