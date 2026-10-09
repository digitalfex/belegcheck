<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pruefstapel extends Model
{
    use HasUlids;

    protected $table = 'pruefstapel';

    protected $guarded = [];

    protected $hidden = ['idempotenz_hash'];

    protected $casts = ['ergebnis' => 'array', 'fertig_am' => 'datetime'];

    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }

    public function auftraege(): HasMany
    {
        return $this->hasMany(Pruefauftrag::class, 'stapel_id')->orderBy('position');
    }
}
