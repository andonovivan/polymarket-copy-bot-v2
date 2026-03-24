<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackedWallet extends Model
{
    protected $fillable = ['address', 'name', 'profile_slug'];
}
