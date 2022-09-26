<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flight extends Model
{
    protected $fillable = [
        'request_id',
        'Itineraries',
    ];

    // Cast attributes JSON to array
    protected $casts = [
        'Itineraries' => 'array'
    ];

}
