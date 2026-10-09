<?php

namespace App\Muster;

use App\Pruefung\PruefErgebnis;
use App\Pruefung\Stufe;
use Carbon\CarbonImmutable;

/**
 * MU-OZ-01: Zwei Belege derselben Person liegen zeitlich zu nah für die Entfernung ihrer Orte
 * (z. B. Wien 19:00 und München 21:00).
 *
 * Bewusst großzügig: Reisezeit = Luftlinie / 120 km/h; ab 400 km wird auch ein Flug angenommen
 * (2,5 h Vorlauf + 700 km/h). Unter 50 km wird nie gemeldet.
 */
final class OrtZeitPruefer
{
    public const MIN_KM = 50;

    /**
     * @param  list<array{id: string|int, zeit: string, lat: float, lon: float, ort: string}>  $belege  Belege einer Person
     * @return array<string|int, list<PruefErgebnis>> je Beleg-ID die Konflikte
     */
    public function pruefe(array $belege): array
    {
        $out = [];
        $n = count($belege);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $belege[$i];
                $b = $belege[$j];
                $km = Ortsbestimmung::entfernungKm($a['lat'], $a['lon'], $b['lat'], $b['lon']);
                if ($km < self::MIN_KM) {
                    continue;
                }
                $ta = CarbonImmutable::parse($a['zeit']);
                $tb = CarbonImmutable::parse($b['zeit']);
                $stunden = abs($ta->diffInMinutes($tb)) / 60;
                $noetig = self::mindestReisezeitStunden($km);

                if ($stunden >= $noetig) {
                    continue;
                }

                $abstand = $stunden < 1 / 60 ? 'zur selben Uhrzeit' : 'nur '.self::dauer($stunden).' auseinander';
                $text = sprintf('Beleg in %s (%s) und Beleg in %s (%s) liegen %d km auseinander, aber %s; für die Strecke sind mindestens %s nötig. Einreicher um Erklärung bitten (z. B. Beleg für Kollegen bezahlt).',
                    $a['ort'], $ta->format('d.m. H:i'), $b['ort'], $tb->format('d.m. H:i'), (int) round($km),
                    $abstand, self::dauer($noetig));

                $out[$a['id']][] = new PruefErgebnis('MU-OZ-01', Stufe::Auffaellig, $text, ['anderer_beleg' => $b['id'], 'km' => round($km)]);
                $out[$b['id']][] = new PruefErgebnis('MU-OZ-01', Stufe::Auffaellig, $text, ['anderer_beleg' => $a['id'], 'km' => round($km)]);
            }
        }

        return $out;
    }

    public static function mindestReisezeitStunden(float $km): float
    {
        $strasse = $km / 120;

        return $km >= 400 ? min($strasse, 2.5 + $km / 700) : $strasse;
    }

    private static function dauer(float $stunden): string
    {
        $min = (int) round($stunden * 60);

        return $min < 60 ? "$min Minuten" : sprintf('%d:%02d Stunden', intdiv($min, 60), $min % 60);
    }
}
