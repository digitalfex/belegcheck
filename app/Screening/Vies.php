<?php

namespace App\Screening;

use App\Models\AusstellerScreening;
use Illuminate\Support\Facades\Http;

/**
 * UID-Prüfung im EU-Register VIES (amtlich, kostenlos). Österreich liefert Name und Anschrift,
 * Deutschland nur gültig/ungültig. Ergebnisse werden zwischengespeichert: gültige 30 Tage, ungültige 1 Tag.
 */
final class Vies
{
    public function __construct(
        private readonly string $url = 'https://ec.europa.eu/taxation_customs/vies/rest-api',
        private readonly int $timeout = 8,
    ) {}

    public static function ausConfig(): self
    {
        return new self(config('belegcheck.screening.vies_url'), (int) config('belegcheck.screening.timeout', 8));
    }

    /** @return array{gueltig: ?bool, name: ?string, adresse: ?string}|null null = Register nicht erreichbar */
    public function pruefe(string $uid): ?array
    {
        $uid = strtoupper(preg_replace('/\s+/', '', $uid));
        if (! preg_match('/^([A-Z]{2})([0-9A-Z]{8,12})$/', $uid, $m)) {
            return ['gueltig' => false, 'name' => null, 'adresse' => null];
        }

        $gespeichert = AusstellerScreening::where('quelle', 'vies')->where('schluessel', $uid)->first();
        if ($gespeichert && $gespeichert->abgerufen_am->gt(now()->subDays($gespeichert->daten['gueltig'] ? 30 : 1))) {
            return $gespeichert->daten;
        }

        try {
            $antwort = Http::timeout($this->timeout)->acceptJson()->get(rtrim($this->url, '/')."/ms/{$m[1]}/vat/{$m[2]}");
        } catch (\Throwable) {
            return null;
        }
        $fehler = $antwort->json('userError');
        if (! $antwort->successful() || ($fehler && ! in_array($fehler, ['VALID', 'INVALID'], true))) {
            return null; // MS_UNAVAILABLE, TIMEOUT … → nicht prüfbar
        }

        $leer = fn ($w) => is_string($w) && trim($w, " -\n") !== '' ? trim($w) : null;
        $daten = ['gueltig' => (bool) $antwort->json('isValid'), 'name' => $leer($antwort->json('name')), 'adresse' => $leer($antwort->json('address'))];
        AusstellerScreening::updateOrCreate(['quelle' => 'vies', 'schluessel' => $uid], ['daten' => $daten, 'abgerufen_am' => now()]);

        return $daten;
    }
}
