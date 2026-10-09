<?php

namespace App\Screening;

use App\Pruefung\PruefErgebnis;
use App\Pruefung\Stufe;

/**
 * Schicht 2: Wer hat den Beleg ausgestellt?
 *
 *  AS-01 UID gültig (VIES)        AS-02 Firmenname zur UID steht auf dem Beleg
 *  AS-03 Lokal am Ort auffindbar  AS-04 Beleg innerhalb der Öffnungszeiten
 *  BR-01 Branche passt zur Spesenkategorie
 *  CO-01 Aussteller-Kategorie laut Richtlinie   CO-02 Positionen laut Richtlinie
 *
 * Grundsatz: Fehlende Daten in offenen Quellen sind kein Verdacht. „Nicht gefunden“ ist nicht prüfbar,
 * nie auffällig; die meisten Abweichungen sind Hinweise.
 */
final class AusstellerPruefer
{
    public function __construct(
        private readonly ?Vies $vies = null,
        private readonly ?Osm $osm = null,
    ) {}

    /**
     * @param  array{uid: ?string, aussteller: ?string, text: string, zweitlesung?: string, zeilen: list<string>,
     *     ort: ?array, zeitpunkt: ?string, kategorie?: ?string}  $beleg
     * @param  array<string, string>  $richtlinie  Compliance-Kategorie → erlaubt | hinweis | pruefen
     * @return array{ergebnisse: list<PruefErgebnis>, daten: array}
     */
    public function pruefe(array $beleg, bool $screening = true, array $richtlinie = []): array
    {
        $ergebnisse = [];
        $daten = [];
        $osmTags = [];
        $textBeide = $beleg['text']."\n".($beleg['zweitlesung'] ?? '');

        if ($screening) {
            [$e, $daten['vies']] = $this->uid($beleg['uid'], $textBeide, $beleg['text'], $beleg['zweitlesung'] ?? '');
            $ergebnisse = [...$ergebnisse, ...$e];
            [$e, $daten['osm']] = $this->lokal($beleg['aussteller'], $beleg['ort'], $beleg['zeitpunkt']);
            $ergebnisse = [...$ergebnisse, ...$e];
            $osmTags = $daten['osm']['tags'] ?? [];
        }

        $branche = Branche::aussteller($osmTags, $beleg['aussteller'], $beleg['text']);
        $daten['branche'] = $branche['branche'];
        $daten['branche_quelle'] = $branche['branche_quelle'];

        // BR-01: Spesenkategorie (vom ERP) ↔ Branche
        $kategorie = $beleg['kategorie'] ?? null;
        if ($kategorie && isset(Branche::KATEGORIE_BRANCHEN[$kategorie])) {
            $ergebnisse[] = match (true) {
                $branche['branche'] === null => new PruefErgebnis('BR-01', Stufe::NichtPruefbar, 'Branche des Ausstellers nicht erkennbar.'),
                in_array($branche['branche'], Branche::KATEGORIE_BRANCHEN[$kategorie], true) => PruefErgebnis::ok('BR-01'),
                default => new PruefErgebnis('BR-01', Stufe::Hinweis,
                    'Spesenkategorie „'.$kategorie.'“, der Aussteller ist aber '.Branche::BRANCHEN[$branche['branche']].' ('.($branche['branche_quelle'] === 'osm' ? 'laut OpenStreetMap' : 'laut Beleginhalt').').',
                    ['kategorie' => $kategorie, 'branche' => $branche['branche']]),
            };
        }

        // CO-01 / CO-02: Compliance-Kategorien laut Richtlinie des Mandanten
        $daten['compliance'] = [];
        $ergebnisse[] = $this->compliance('CO-01', $branche['kategorien'], $richtlinie, $daten['compliance'],
            fn ($k, $quelle) => 'Der Aussteller gehört zur Kategorie „'.Branche::COMPLIANCE[$k]['titel'].'“ ('.($quelle === 'osm' ? 'laut OpenStreetMap' : 'laut Name').').');
        $ergebnisse[] = $this->compliance('CO-02', Branche::positionen($beleg['zeilen']), $richtlinie, $daten['compliance'],
            fn ($k, $zeile) => 'Position der Kategorie „'.Branche::COMPLIANCE[$k]['titel'].'“ auf dem Beleg: „'.mb_substr($zeile, 0, 60).'“.');

        return ['ergebnisse' => $ergebnisse, 'daten' => $daten];
    }

    private function compliance(string $code, array $gefunden, array $richtlinie, array &$protokoll, \Closure $text): PruefErgebnis
    {
        $schlimmste = null;
        $saetze = [];
        foreach ($gefunden as $kategorie => $fundstelle) {
            $stufe = $richtlinie[$kategorie] ?? Branche::COMPLIANCE[$kategorie]['standard'];
            $protokoll[] = ['regel' => $code, 'kategorie' => $kategorie, 'richtlinie' => $stufe];
            if ($stufe === 'erlaubt') {
                continue;
            }
            $s = $stufe === 'pruefen' ? Stufe::Auffaellig : Stufe::Hinweis;
            $schlimmste = $schlimmste === null || $s->punkte() > $schlimmste->punkte() ? $s : $schlimmste;
            $saetze[] = $text($kategorie, $fundstelle);
        }

        return $schlimmste === null
            ? PruefErgebnis::ok($code)
            : new PruefErgebnis($code, $schlimmste, implode(' ', $saetze).' Laut Richtlinie des Unternehmens zu prüfen.', ['kategorien' => array_keys($gefunden)]);
    }

    /** @return array{0: list<PruefErgebnis>, 1: ?array} */
    private function uid(?string $uid, string $textBeide, string $lesungA, string $lesungB): array
    {
        if ($uid === null) {
            return [[new PruefErgebnis('AS-01', Stufe::NichtPruefbar, 'Keine UID auf dem Beleg gefunden (bei Kleinbetragsrechnungen nicht vorgeschrieben).')], null];
        }
        $vies = ($this->vies ?? Vies::ausConfig())->pruefe($uid);
        if ($vies === null) {
            return [[new PruefErgebnis('AS-01', Stufe::NichtPruefbar, 'EU-Register VIES derzeit nicht erreichbar.')], null];
        }

        $ergebnisse = [];
        if (! $vies['gueltig']) {
            // Steht die UID in beiden Texterkennungen gleich, ist ein Lesefehler unwahrscheinlich
            $ohne = fn ($t) => str_replace(' ', '', $t);
            $beideGleich = $lesungB !== '' && str_contains($ohne($lesungA), $uid) && str_contains($ohne($lesungB), $uid);
            $ergebnisse[] = new PruefErgebnis('AS-01', $beideGleich ? Stufe::Auffaellig : Stufe::Hinweis,
                "Die UID {$uid} ist laut EU-Register VIES nicht gültig".($beideGleich ? '.' : ' – möglicherweise ein Lesefehler, bitte am Bild prüfen.'),
                ['uid' => $uid]);

            return [$ergebnisse, $vies];
        }
        $ergebnisse[] = PruefErgebnis::ok('AS-01', "UID {$uid} gültig".($vies['name'] ? " ({$vies['name']})." : '.'));

        if ($vies['name'] === null) {
            $ergebnisse[] = new PruefErgebnis('AS-02', Stufe::NichtPruefbar, 'Das Register liefert für dieses Land keinen Firmennamen.');
        } else {
            $woerter = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($vies['name']), -1, PREG_SPLIT_NO_EMPTY),
                fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, ['gmbh', 'gesmbh', 'gesellschaft', 'haftung', 'beschränkter', 'kommanditgesellschaft', 'aktiengesellschaft'], true));
            $text = mb_strtolower($textBeide);
            $treffer = array_filter($woerter, fn ($w) => str_contains($text, $w));
            $ergebnisse[] = $woerter === [] || $treffer !== []
                ? PruefErgebnis::ok('AS-02', "Firma laut Register: {$vies['name']}.")
                : new PruefErgebnis('AS-02', Stufe::Hinweis,
                    "Laut Register gehört die UID zu „{$vies['name']}“ – der Name steht nicht auf dem Beleg (Markenname, Lesefehler oder fremde UID).",
                    ['register' => $vies['name']]);
        }

        return [$ergebnisse, $vies];
    }

    /** @return array{0: list<PruefErgebnis>, 1: ?array} */
    private function lokal(?string $name, ?array $ort, ?string $zeitpunkt): array
    {
        if (! $name || ! $ort || ! isset($ort['lat'], $ort['lon'])) {
            return [[new PruefErgebnis('AS-03', Stufe::NichtPruefbar, 'Name oder Ort des Ausstellers nicht erkennbar.')], null];
        }
        $osm = ($this->osm ?? Osm::ausConfig())->suche($name, (float) $ort['lat'], (float) $ort['lon']);
        if ($osm === null) {
            return [[new PruefErgebnis('AS-03', Stufe::NichtPruefbar, 'OpenStreetMap derzeit nicht erreichbar.')], null];
        }
        if (! $osm['gefunden']) {
            return [[new PruefErgebnis('AS-03', Stufe::NichtPruefbar,
                "„{$name}“ in OpenStreetMap rund um {$ort['plz']} {$ort['ort']} nicht gefunden – die Karte ist nicht vollständig, daher kein Hinweis.")], $osm];
        }

        $ergebnisse = [PruefErgebnis::ok('AS-03', "Gefunden: {$osm['name']}".($osm['entfernung_m'] !== null ? " ({$osm['entfernung_m']} m vom PLZ-Mittelpunkt)." : '.'))];
        $zeiten = $osm['tags']['opening_hours'] ?? null;
        if ($zeiten === null || $zeitpunkt === null) {
            $ergebnisse[] = new PruefErgebnis('AS-04', Stufe::NichtPruefbar, 'Keine Öffnungszeiten hinterlegt oder Belegzeit unbekannt.');
        } else {
            $ausserhalb = (new Oeffnungszeiten($zeiten))->minutenAusserhalb(new \DateTimeImmutable($zeitpunkt));
            $ergebnisse[] = match (true) {
                $ausserhalb === null => new PruefErgebnis('AS-04', Stufe::NichtPruefbar, "Öffnungszeiten „{$zeiten}“ nicht auswertbar."),
                $ausserhalb === 0 => PruefErgebnis::ok('AS-04', "Innerhalb der Öffnungszeiten ({$zeiten})."),
                // Küche/Kassa schließt oft später, Öffnungszeiten ändern sich → erst ab 90 Minuten auffällig
                $ausserhalb <= 90 => new PruefErgebnis('AS-04', Stufe::Hinweis,
                    "Beleg {$ausserhalb} Minuten außerhalb der Öffnungszeiten laut OpenStreetMap ({$zeiten}).", ['oeffnungszeiten' => $zeiten]),
                default => new PruefErgebnis('AS-04', Stufe::Auffaellig,
                    'Beleg deutlich außerhalb der Öffnungszeiten laut OpenStreetMap ('.$zeiten.', Beleg '.substr($zeitpunkt, 11, 5).' Uhr). Öffnungszeiten können sich geändert haben.',
                    ['oeffnungszeiten' => $zeiten]),
            };
        }

        return [$ergebnisse, $osm];
    }
}
