<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pruefauftrag extends Model
{
    use HasUlids;

    protected $table = 'pruefauftraege';

    protected $guarded = [];

    protected $hidden = ['ablage_pfad', 'idempotenz_hash'];

    protected $casts = ['angaben' => 'array', 'ergebnis' => 'array', 'fertig_am' => 'datetime', 'beleg_zeit' => 'datetime', 'abdeckung' => 'float'];

    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }
}
