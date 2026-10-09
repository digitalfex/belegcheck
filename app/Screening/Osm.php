<?php

namespace App\Screening;

use App\Models\AusstellerScreening;
use App\Muster\Ortsbestimmung;
use Illuminate\Support\Facades\Http;

/**
 * Sucht das Lokal/Geschäft in OpenStreetMap (Overpass-Schnittstelle) nach Name rund um den Ort des Belegs.
 * Öffentliche Overpass-Server sind für geringe Mengen gedacht; für viele Abfragen eigenen Server eintragen
 * (BELEG_OVERPASS_URL). Ergebnisse werden je Aussteller 30 Tage zwischengespeichert.
 */
final class Osm
{
    /** Wörter, die keinen Namen unterscheiden */
    private const ALLGEMEIN = ['gmbh', 'gesmbh', 'gesellschaft', 'restaurant', 'cafe', 'café', 'gasthaus', 'gasthof', 'hotel', 'bar',
        'wien', 'graz', 'linz', 'salzburg', 'innsbruck', 'münchen', 'berlin', 'und', 'the', 'der', 'die', 'das', 'zum', 'zur',
        'beisl', 'heuriger', 'weinbau', 'pizzeria', 'trattoria', 'kaffeehaus', 'bistro', 'tankstelle', 'center', 'franchise'];

    public function __construct(
        private readonly string $url = 'https://overpass-api.de/api/interpreter',
        private readonly int $timeout = 10,
    ) {}

    public static function ausConfig(): self
    {
        return new self(config('belegcheck.screening.overpass_url'), (int) config('belegcheck.screening.timeout', 8) + 4);
    }

    /** Kennwort für die Suche: längstes unterscheidendes Wort des Namens */
    public static function kennwort(string $name): ?string
    {
        $woerter = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        $woerter = array_values(array_filter($woerter, fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, self::ALLGEMEIN, true) && ! ctype_digit($w)));
        usort($woerter, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $woerter[0] ?? null;
    }

    /**
     * @return array{gefunden: bool, name?: string, tags?: array<string, string>, entfernung_m?: int}|null null = nicht erreichbar
     */
    public function suche(string $name, float $lat, float $lon, int $radiusM = 3000): ?array
    {
        $kennwort = self::kennwort($name);
        if ($kennwort === null) {
            return ['gefunden' => false];
        }
        $schluessel = $kennwort.'|'.round($lat, 2).'|'.round($lon, 2);
        $gespeichert = AusstellerScreening::where('quelle', 'osm')->where('schluessel', $schluessel)->first();
        if ($gespeichert && $gespeichert->abgerufen_am->gt(now()->subDays(30))) {
            return $gespeichert->daten;
        }

        $muster = preg_quote($kennwort, '"');
        $abfrage = "[out:json][timeout:{$this->timeout}];nwr(around:{$radiusM},{$lat},{$lon})[\"name\"~\"{$muster}\",i];out tags center 20;";
        try {
            $antwort = Http::timeout($this->timeout + 2)->withUserAgent('Belegcheck/1.0 (DIGITALFEX)')
                ->asForm()->post($this->url, ['data' => $abfrage]);
        } catch (\Throwable) {
            return null;
        }
        if (! $antwort->successful()) {
            return null;
        }

        $bester = null;
        foreach ((array) $antwort->json('elements', []) as $e) {
            $tags = (array) ($e['tags'] ?? []);
            $elat = $e['lat'] ?? $e['center']['lat'] ?? null;
            $elon = $e['lon'] ?? $e['center']['lon'] ?? null;
            similar_text(mb_strtolower($tags['name'] ?? ''), mb_strtolower($name), $aehnlich);
            // Geschäfte/Lokale vor Straßen, Haltestellen u. Ä. gleichen Namens
            $poi = (bool) array_intersect(array_keys($tags), ['amenity', 'shop', 'tourism', 'leisure', 'craft', 'office']);
            $wert = $aehnlich + ($poi ? 50 : 0);
            if ($poi && ($bester === null || $wert > $bester['wert'])) {
                $bester = ['wert' => $wert, 'tags' => $tags, 'lat' => $elat, 'lon' => $elon];
            }
        }

        $daten = $bester === null ? ['gefunden' => false] : [
            'gefunden' => true,
            'name' => $bester['tags']['name'] ?? $kennwort,
            'tags' => array_intersect_key($bester['tags'], array_flip(['name', 'amenity', 'shop', 'tourism', 'leisure', 'craft', 'office', 'cuisine',
                'opening_hours', 'website', 'addr:street', 'addr:housenumber', 'addr:postcode', 'addr:city', 'brand'])),
            'entfernung_m' => $bester['lat'] ? (int) round(Ortsbestimmung::entfernungKm($lat, $lon, $bester['lat'], $bester['lon']) * 1000) : null,
        ];
        AusstellerScreening::updateOrCreate(['quelle' => 'osm', 'schluessel' => $schluessel], ['daten' => $daten, 'abgerufen_am' => now()]);

        return $daten;
    }
}
