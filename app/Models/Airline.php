<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Airline extends Model
{
    protected $guarded = ['id'];
    public $timestamps = false;

    public function offer()
    {
        return $this->hasMany(Offer::class);
    }
}
