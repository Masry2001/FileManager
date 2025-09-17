<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    //
    // Allow everything on create
    protected $guarded = [];

    // No need for $fillable if we are using guarded = []
}
