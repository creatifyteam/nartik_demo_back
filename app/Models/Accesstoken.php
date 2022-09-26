<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Accesstoken extends Model
{
    protected $table = 'accesstoken';
    protected $fillable = ['access_token', 'token_type', 'expires_in'];
}
