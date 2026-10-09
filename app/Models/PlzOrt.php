<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlzOrt extends Model
{
    protected $table = 'plz_orte';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['lat' => 'float', 'lon' => 'float'];
}
