<?php

namespace App\Pruefung;

use App\Belegleser\BelegleserClient;
use App\Fiskal\BelegTextAuswertung;
use App\Fiskal\DsfinvK\DsfinvkBeleg;
use App\Fiskal\DsfinvK\DsfinvkParser;
use App\Fiskal\DsfinvK\DsfinvkPruefer;
use App\Fiskal\GedruckteWerte;
use App\Fiskal\KassenDaten;
use App\Fiskal\KassenGedaechtnis;
use App\Fiskal\KiLesung;
use App\Fiskal\Rksv\RksvBeleg;
use App\Fiskal\Rksv\RksvParser;
use App\Fiskal\Rksv\RksvPruefer;
use App\Forensik\ForensikPruefer;
use App\Hashes\BelegFingerabdruck;
use App\Muster\Ortsbestimmung;
use App\Sprachmodell\BelegSprachmodell;

/**
 * Ein Beleg → Prüfbericht. Gemeinsam genutzt von Kommandozeile und Weboberfläche.
 */
final class BelegPruefService
{
    public function __construct(
        private readonly ?BelegleserClient $leser = null,
        private readonly RksvPruefer $rksv = new RksvPruefer,
        private readonly DsfinvkPruefer $dsfinvk = new DsfinvkPruefer,
        private readonly KassenGedaechtnis $gedaechtnis = new KassenGedaechtnis,
        private readonly ?BelegSprachmodell $sprachmodell = null,
    ) {}

    /** Erster Kassen-QR-Code im Bild: RKSV (AT) vor DSFinV-K (DE). */
    public static function kassenQr(array $codes): ?string
    {
        $texte = array_column($codes, 'text');

        return collect($texte)->first(fn ($t) => RksvParser::istRksv($t))
            ?? collect($texte)->first(fn ($t) => DsfinvkParser::istDsfinvk($t));
    }

    /** QR-Text → geparster Beleg (AT oder DE) oder null. */
    public static function fiskalBeleg(?string $qr): RksvBeleg|DsfinvkBeleg|null
    {
        if ($qr === null) {
            return null;
        }
        try {
            return match (true) {
                RksvParser::istRksv($qr) => (new RksvParser)->parse($qr),
                DsfinvkParser::istDsfinvk($qr) => (new DsfinvkParser)->parse($qr),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    public static function kassenDaten(RksvBeleg|DsfinvkBeleg|null $b): ?KassenDaten
    {
        return match (true) {
            $b instanceof RksvBeleg => KassenDaten::ausRksv($b),
            $b instanceof DsfinvkBeleg => KassenDaten::ausDsfinvk($b),
            default => null,
        };
    }

    /**
     * Prüft eine Datei (Foto/PDF): QR-Code und Text lesen, gedruckte Werte automatisch ermitteln.
     * Von Hand übergebene gedruckte Werte haben Vorrang vor der Texterkennung.
     */
    public function pruefeDatei(string $pfad, ?GedruckteWerte $gedruckt = null): array
    {
        $gelesen = ($this->leser ?? BelegleserClient::ausConfig())->lesen($pfad);
        $codes = $gelesen['codes'] ?? [];
        $qr = self::kassenQr($codes);

        $text = new BelegTextAuswertung($gelesen['zeilen'] ?? [], $gelesen['alternativen'] ?? []);
        $fiskal = self::fiskalBeleg($qr);
        $kasse = self::kassenDaten($fiskal);
        $ki = ['genutzt' => false, 'felder' => []];
        $kiAussteller = null;
        if ($gedruckt === null) {
            $gedruckt = $text->gedruckteWerte($fiskal);
            [$gedruckt, $ki, $kiAussteller] = $this->mitSprachmodell($gedruckt, $fiskal, $gelesen, $kasse?->land ?? $text->land());
        }

        $uid = $text->uid();
        $datum = $gedruckt->datumUhrzeit ?? $kasse?->zeit->format('Y-m-d H:i');
        $dateiSha = BelegFingerabdruck::datei($pfad);

        $bericht = [
            'datei_sha256' => $dateiSha,
            'gefundene_codes' => $codes,
            'text' => $text->text(),
            'text_quelle' => $gelesen['quelle'] ?? 'ocr',
            'zweitlesung' => $gelesen['zweitlesung'] ?? null,
            'text_sicherheit' => $gelesen['sicherheit'] ?? null,
            'uid' => $uid,
            'land' => $kasse?->land ?? $text->land(),
            'aussteller' => $kiAussteller ?? $text->aussteller(),
            'aussteller_ocr' => $text->aussteller(),
            'ki' => $ki,
            'ort' => (new Ortsbestimmung)->bestimme(explode("\n", $text->text()), $kasse?->land ?? $text->land()),
            'gedruckt' => [
                'gesamt_cent' => $gedruckt->gesamtCent,
                'betraege_je_satz_cent' => $gedruckt->betraegeJeSatzCent,
                'datum_uhrzeit' => $gedruckt->datumUhrzeit,
                'kassen_id' => $gedruckt->kassenId,
                'tse_seriennummer' => $gedruckt->tseSeriennummer,
                'lesesicherheit' => $gedruckt->lesesicherheit,
            ],
            // Fingerabdrücke gegen Doppel-Einreichungen (MU-DU-04/-05)
            'inhalt_hash' => BelegFingerabdruck::inhalt(
                $uid ?? $kasse?->kennung ?? $text->aussteller(),
                $datum ? substr($datum, 0, 10) : null,
                $datum ? substr($datum, 11, 5) : null,
                $gedruckt->gesamtCent ?? $kasse?->summeCent,
                $kasse?->belegnummer,
            ),
            'text_hash' => BelegFingerabdruck::text($text->text()),
            'forensik' => $gelesen['forensik'] ?? null,
            'vorschau' => $gelesen['vorschau'] ?? [],
        ];

        $zusatz = [
            ...(isset($gelesen['forensik']) ? (new ForensikPruefer)->pruefe($gelesen['forensik'], $datum) : []),
            ...($kasse ? $this->gedaechtnis->pruefe($kasse, $uid, $text->aussteller(), $dateiSha) : []),
        ];

        $ergebnis = $this->pruefeQr($qr, $gedruckt, $zusatz, $bericht['land'], $text->tseKlartext());

        // Gedächtnis lernt automatisch nur aus grünen Belegen; gelbe/rote erst nach Bestätigung durch den Prüfer
        $ergebnis['im_gedaechtnis'] = false;
        if ($kasse && $ergebnis['ampel'] === 'gruen') {
            $this->gedaechtnis->merke($kasse, $uid, $text->aussteller(), $dateiSha);
            $ergebnis['im_gedaechtnis'] = true;
        }

        return [...$bericht, ...$ergebnis];
    }

    /**
     * Zweite Meinung des lokalen Sprachmodells. Es liest beide Texterkennungen und liefert Summe, Datum,
     * Steuersätze und Aussteller. Übernommen wird ein KI-Wert nur,
     *  – wenn er im Text vorkommt (KiLesung prüft das) und
     *  – wenn er einen fehlenden Wert ergänzt (Lesesicherheit 0,7 → höchstens Hinweis) oder
     *    den Wert aus dem QR-Code bestätigt, wo die Texterkennung etwas anderes gelesen hat.
     * Eine KI-Lesung, die dem QR-Code widerspricht, ersetzt nie einen Wert der Texterkennung.
     *
     * @return array{0: GedruckteWerte, 1: array, 2: ?string}
     */
    private function mitSprachmodell(GedruckteWerte $g, RksvBeleg|DsfinvkBeleg|null $fiskal, array $gelesen, ?string $land): array
    {
        $modell = $this->sprachmodell ?? BelegSprachmodell::ausConfig();
        $qrSumme = $fiskal?->summeCent();
        $qrZeit = match (true) {
            $fiskal instanceof RksvBeleg => substr(str_replace('T', ' ', $fiskal->datumUhrzeit), 0, 16),
            $fiskal instanceof DsfinvkBeleg => substr((string) $fiskal->endeLokal(), 0, 16),
            default => null,
        };
        $qrSaetze = match (true) {
            $fiskal instanceof RksvBeleg => array_filter($fiskal->betraegeCent),
            $fiskal instanceof DsfinvkBeleg => array_filter($fiskal->bruttoCent ?? []),
            default => null,
        };
        $datum = $g->datumUhrzeit ? substr($g->datumUhrzeit, 0, 16) : null;

        $bedarf = (bool) config('belegcheck.sprachmodell.immer')
            || $g->gesamtCent === null || $datum === null
            || ($qrSumme !== null && $g->gesamtCent !== $qrSumme)
            || ($qrZeit !== null && $datum !== $qrZeit)
            || ($qrSaetze && $g->betraegeJeSatzCent !== null && array_filter($g->betraegeJeSatzCent) != $qrSaetze);

        if (! $bedarf || ! $modell->verfuegbar()) {
            return [$g, ['genutzt' => false, 'felder' => [], 'grund' => $bedarf ? 'nicht eingerichtet' : 'nicht nötig'], null];
        }

        $lesungA = (string) ($gelesen['text'] ?? '');
        $lesungB = (string) ($gelesen['zweitlesung'] ?? '');
        $start = microtime(true);
        $antwort = $modell->lesen($lesungA, $lesungB);
        $dauer = (int) round((microtime(true) - $start) * 1000);
        if ($antwort === null) {
            return [$g, ['genutzt' => false, 'felder' => [], 'grund' => 'keine Antwort', 'dauer_ms' => $dauer], null];
        }

        $ki = new KiLesung($antwort, $lesungA, $lesungB, $land);
        $felder = [];
        $sicherheit = $g->lesesicherheit;
        $gesamt = $g->gesamtCent;
        $betraege = $g->betraegeJeSatzCent;
        $zeit = $g->datumUhrzeit;

        $uebernehmen = function (string $feld, mixed $alt, mixed $kiWert, mixed $qrWert) use (&$felder, &$sicherheit): mixed {
            if ($kiWert === null || $kiWert === $alt) {
                return $alt;
            }
            if ($qrWert !== null && $kiWert === $qrWert) { // KI liest, was der QR-Code sagt → bestätigt
                $felder[] = $feld;
                $sicherheit[$feld] = max($sicherheit[$feld] ?? 0, 0.85);

                return $kiWert;
            }
            if ($alt === null) { // Lücke füllen, aber nie als sicher
                $felder[] = $feld;
                $sicherheit[$feld] = KiLesung::SICHERHEIT;

                return $kiWert;
            }

            return $alt;
        };

        $gesamt = $uebernehmen('gesamt', $gesamt, $ki->gesamtCent(), $qrSumme);
        $zeitKi = $ki->datumUhrzeit();
        $zeitNeu = $uebernehmen('datum_uhrzeit', $datum, $zeitKi, $qrZeit);
        $zeit = $zeitNeu === $datum ? $zeit : $zeitNeu;
        $sortiert = function (?array $a): ?array {
            if ($a === null || ($a = array_filter($a)) === []) {
                return null;
            }
            ksort($a);

            return $a;
        };
        $betraege = $uebernehmen('betraege', $sortiert($betraege), $sortiert($ki->betraegeJeSatz()), $sortiert($qrSaetze))
            ?? $g->betraegeJeSatzCent;

        $neu = new GedruckteWerte($gesamt, $betraege, $zeit, $g->kassenId, $sicherheit, $g->tseSeriennummer);

        return [$neu, ['genutzt' => true, 'felder' => $felder, 'dauer_ms' => $dauer, 'aussteller' => $ki->aussteller()], $ki->aussteller()];
    }

    /** Prüfer bestätigt einen gelben/roten Beleg als in Ordnung → ins Kassen-Gedächtnis. */
    public function bestaetige(string $qr, ?string $uid, ?string $aussteller, ?string $dateiSha): bool
    {
        $kasse = self::kassenDaten(self::fiskalBeleg($qr));
        if (! $kasse) {
            return false;
        }
        $this->gedaechtnis->merke($kasse, $uid, $aussteller, $dateiSha);

        return true;
    }

    /**
     * Prüft einen bereits gelesenen QR-Inhalt (oder den Fall „kein QR-Code“).
     *
     * @param  list<PruefErgebnis>  $zusatz  Ergebnisse weiterer Schichten (Bildforensik, Gedächtnis) – fließen in den Risikowert ein
     * @param  string|null  $land  aus dem Text erkanntes Land, wenn kein QR-Code gefunden wurde
     * @param  array<string, bool>|null  $tseKlartext  DE: welche TSE-Angaben im Text stehen
     */
    public function pruefeQr(?string $qr, ?GedruckteWerte $gedruckt = null, array $zusatz = [], ?string $land = null, ?array $tseKlartext = null): array
    {
        $beleg = null;

        if ($qr !== null && DsfinvkParser::istDsfinvk($qr)) {
            ['beleg' => $beleg, 'ergebnisse' => $ergebnisse] = $this->dsfinvk->pruefe($qr, $gedruckt);
        } elseif ($qr !== null) {
            ['beleg' => $beleg, 'ergebnisse' => $ergebnisse] = $this->rksv->pruefe($qr, $gedruckt);
        } else {
            $ergebnisse = [$this->ohneQr($land, $tseKlartext)];
        }

        $ergebnisse = [...$ergebnisse, ...$zusatz];
        $bewertung = new Risikobewertung($ergebnisse);
        $kasse = self::kassenDaten($beleg);

        return [
            'qr_text' => $qr,
            'qr_typ' => match (true) {
                $beleg instanceof RksvBeleg => 'rksv',
                $beleg instanceof DsfinvkBeleg => 'dsfinvk',
                default => null,
            },
            'qr' => $beleg?->toArray(),
            // Fiskal-Schlüssel für Dubletten über Fotos hinweg: Kasse/TSE + Beleg-/Transaktionsnummer
            'fiskal_schluessel' => $kasse ? hash('sha256', $kasse->land.'|'.$kasse->kennung.'|'.$kasse->belegnummer) : null,
            'risikowert' => $bewertung->risikowert(),
            'ampel' => $bewertung->ampel(),
            'ergebnisse' => array_map(fn (PruefErgebnis $e) => $e->toArray(), $ergebnisse),
        ];
    }

    /** Kein Kassen-QR-Code: je nach Land Hinweis oder Prüfung des TSE-Klartexts. */
    private function ohneQr(?string $land, ?array $tse): PruefErgebnis
    {
        if ($land === 'AT') {
            return new PruefErgebnis('AT-QR-01', Stufe::Hinweis,
                'Österreichischer Beleg ohne Kassen-QR-Code. Registrierkassen müssen ihn drucken; Ausnahmen sind z. B. Rechnungen, die per Überweisung bezahlt werden. Original ansehen.');
        }

        if ($land === 'DE') {
            $fehlend = array_keys(array_filter($tse ?? [], fn ($da) => ! $da));
            $namen = ['tse_seriennummer' => 'TSE-Seriennummer', 'transaktionsnummer' => 'Transaktionsnummer', 'signaturzaehler' => 'Signaturzähler',
                'start' => 'Vorgangsbeginn', 'ende' => 'Vorgangsende', 'pruefwert' => 'Prüfwert/Signatur'];

            if ($fehlend === []) {
                return PruefErgebnis::ok('DE-TX-01', 'TSE-Angaben im Klartext vorhanden (kein QR-Code).');
            }
            if (count($fehlend) === count($namen)) {
                return new PruefErgebnis('DE-TX-01', Stufe::Hinweis,
                    'Deutscher Beleg ohne TSE-Angaben (weder QR-Code noch Klartext). Kassenbelege müssen sie enthalten; Ausnahmen sind z. B. Rechnungen aus Fakturierungs-Software oder bestimmte Automaten.');
            }

            return new PruefErgebnis('DE-TX-01', Stufe::Hinweis,
                'TSE-Angaben im Klartext unvollständig, es fehlen: '.implode(', ', array_map(fn ($f) => $namen[$f], $fehlend)).'. Kann auch an der Texterkennung liegen; bitte am Bild prüfen.');
        }

        return new PruefErgebnis('LAND-01', Stufe::NichtPruefbar,
            'Kein Kassen-QR-Code und kein österreichischer oder deutscher Beleg erkannt (z. B. Auslandsbeleg). Es gelten nur die allgemeinen Prüfungen.');
    }
}
