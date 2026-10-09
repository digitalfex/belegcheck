<?php

namespace App\Pruefung;

use App\Belegleser\BelegleserClient;
use App\Fiskal\GedruckteWerte;
use App\Fiskal\Rksv\RksvParser;
use App\Fiskal\Rksv\RksvPruefer;
use App\Hashes\BelegFingerabdruck;

/**
 * Ein Beleg → Prüfbericht. Gemeinsam genutzt von Kommandozeile und Weboberfläche.
 */
final class BelegPruefService
{
    public function __construct(
        private readonly ?BelegleserClient $leser = null,
        private readonly RksvPruefer $rksv = new RksvPruefer,
    ) {}

    /** Prüft eine Datei (Foto/PDF). */
    public function pruefeDatei(string $pfad, ?GedruckteWerte $gedruckt = null): array
    {
        $codes = ($this->leser ?? BelegleserClient::ausConfig())->qrCodes($pfad);
        $qr = collect($codes)->first(fn ($c) => RksvParser::istRksv($c['text']))['text'] ?? null;

        return [
            'datei_sha256' => BelegFingerabdruck::datei($pfad),
            'gefundene_codes' => $codes,
            ...$this->pruefeQr($qr, $gedruckt),
        ];
    }

    /** Prüft einen bereits gelesenen QR-Inhalt (oder null, wenn keiner gefunden wurde). */
    public function pruefeQr(?string $qr, ?GedruckteWerte $gedruckt = null): array
    {
        if ($qr === null) {
            $ergebnisse = [new PruefErgebnis('AT-QR-01', Stufe::NichtPruefbar,
                'Kein österreichischer Kassen-QR-Code gefunden. Sprint 1 prüft nur AT-QR-Codes; ob der Code fehlen darf, prüft ab Sprint 2 die Landeserkennung.')];
            $beleg = null;
        } else {
            ['beleg' => $beleg, 'ergebnisse' => $ergebnisse] = $this->rksv->pruefe($qr, $gedruckt);
        }

        $bewertung = new Risikobewertung($ergebnisse);

        return [
            'qr_text' => $qr,
            'qr' => $beleg?->toArray(),
            // Fiskal-Schlüssel für Dubletten über Fotos hinweg (AT-KA-05): Kassen-ID + Belegnummer
            'fiskal_schluessel' => $beleg ? hash('sha256', $beleg->kassenId.'|'.$beleg->belegnummer) : null,
            'risikowert' => $bewertung->risikowert(),
            'ampel' => $bewertung->ampel(),
            'ergebnisse' => array_map(fn (PruefErgebnis $e) => $e->toArray(), $ergebnisse),
        ];
    }
}
