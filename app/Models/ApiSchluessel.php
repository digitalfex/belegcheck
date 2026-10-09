<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * API-Schlüssel im Format „bc_<präfix>_<geheimnis>“. Gespeichert wird nur der SHA-256-Hash.
 */
class ApiSchluessel extends Model
{
    protected $table = 'api_schluessel';

    protected $guarded = [];

    protected $casts = ['zuletzt_genutzt_am' => 'datetime', 'widerrufen_am' => 'datetime'];

    /** @return array{0: self, 1: string} Modell und der Klartext-Schlüssel (nur jetzt sichtbar) */
    public static function erzeugen(Mandant $mandant, string $name): array
    {
        $praefix = Str::lower(Str::random(8));
        $klartext = 'bc_'.$praefix.'_'.Str::random(40);
        $schluessel = self::create(['mandant_id' => $mandant->id, 'name' => $name, 'praefix' => $praefix, 'hash' => hash('sha256', $klartext)]);

        return [$schluessel, $klartext];
    }

    public static function finden(string $klartext): ?self
    {
        if (! preg_match('/^bc_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', $klartext)) {
            return null;
        }

        return self::where('hash', hash('sha256', $klartext))->whereNull('widerrufen_am')->with('mandant')->first();
    }

    public function mandant(): BelongsTo
    {
        return $this->belongsTo(Mandant::class);
    }
}
