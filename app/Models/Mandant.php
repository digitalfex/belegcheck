<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kunde der Schnittstelle. Einstellungen (alle optional):
 *  - schwellen: freigabe_ab (Score), min_abdeckung (0–1)
 *  - compliance: Kategorie → erlaubt | hinweis | pruefen
 *  - screening: true/false (Aussteller über VIES/OpenStreetMap prüfen)
 */
class Mandant extends Model
{
    protected $table = 'mandanten';

    protected $guarded = [];

    protected $hidden = ['webhook_geheimnis'];

    protected $casts = ['einstellungen' => 'array', 'webhook_geheimnis' => 'encrypted', 'aktiv' => 'boolean'];

    public const STANDARD = [
        'schwellen' => ['freigabe_ab' => 86, 'min_abdeckung' => 0.6],
        'screening' => true,
        'compliance' => [],
    ];

    public function einstellung(string $schluessel, mixed $standard = null): mixed
    {
        return data_get($this->einstellungen ?? [], $schluessel, data_get(self::STANDARD, $schluessel, $standard));
    }

    public function schluessel(): HasMany
    {
        return $this->hasMany(ApiSchluessel::class);
    }

    public function auftraege(): HasMany
    {
        return $this->hasMany(Pruefauftrag::class);
    }
}
