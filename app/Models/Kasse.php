<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kasse extends Model
{
    protected $table = 'kassen';

    protected $guarded = [];

    protected $casts = ['erster_beleg_am' => 'datetime', 'letzter_beleg_am' => 'datetime'];

    public function belege(): HasMany
    {
        return $this->hasMany(KassenBeleg::class);
    }
}
