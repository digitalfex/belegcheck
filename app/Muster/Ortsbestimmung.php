<?php

namespace App\Muster;

use App\Models\PlzOrt;

/**
 * Ort eines Belegs aus PLZ + Ortsname im Kopf des Belegs, Koordinaten aus dem PLZ-Verzeichnis (offline).
 */
final class Ortsbestimmung
{
    /**
     * @param  list<string>  $zeilen  Textzeilen des Belegs (Kopf zuerst)
     * @return array{plz: string, ort: string, land: string, lat: float, lon: float}|null
     */
    public function bestimme(array $zeilen, ?string $land): ?array
    {
        // Adresse steht fast immer in den ersten Zeilen
        foreach (array_slice($zeilen, 0, 10) as $zeile) {
            if (! preg_match('/(?:^|[\s,])(?:(A|D|AT|DE)[-\s])?(\d{4,5})\s+([A-ZÄÖÜ][\p{L}.\- ]{2,40})/u', $zeile, $m)) {
                continue;
            }
            $plz = $m[2];
            $l = match (true) {
                in_array($m[1], ['A', 'AT'], true) => 'AT',
                in_array($m[1], ['D', 'DE'], true) => 'DE',
                strlen($plz) === 4 => 'AT',
                default => 'DE',
            };
            if ($land && $land !== $l && strlen($plz) === ($land === 'AT' ? 4 : 5)) {
                $l = $land;
            }

            $treffer = PlzOrt::where('land', $l)->where('plz', $plz)->get();
            if ($treffer->isEmpty()) {
                continue;
            }
            // Bei mehreren Orten je PLZ: der zum gedruckten Namen ähnlichste
            $name = trim($m[3]);
            $bester = $treffer->sortByDesc(function ($t) use ($name) {
                similar_text(mb_strtolower($t->ort), mb_strtolower($name), $p);

                return $p;
            })->first();

            return ['plz' => $plz, 'ort' => $bester->ort, 'land' => $l, 'lat' => $bester->lat, 'lon' => $bester->lon];
        }

        return null;
    }

    /** Luftlinie in km (Haversine). */
    public static function entfernungKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
