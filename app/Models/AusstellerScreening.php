<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AusstellerScreening extends Model
{
    protected $table = 'aussteller_screenings';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['daten' => 'array', 'abgerufen_am' => 'datetime'];
}
