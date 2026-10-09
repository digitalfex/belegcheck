<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KassenBeleg extends Model
{
    protected $table = 'kassen_belege';

    protected $guarded = [];

    protected $casts = ['beleg_zeit' => 'datetime'];

    public function kasse(): BelongsTo
    {
        return $this->belongsTo(Kasse::class);
    }
}
