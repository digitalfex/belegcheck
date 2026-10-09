<?php

namespace App\Fiskal\Rksv;

use App\Fiskal\GedruckteWerte;
use App\Pruefung\PruefErgebnis;
use App\Pruefung\Stufe;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Schicht 1 Österreich: Regeln AT-QR-02 bis AT-QR-11 (Spezifikation, Abschnitt „Schicht 1 Österreich“).
 *
 * Begründungstexte: beschreiben, was nicht zusammenpasst, nennen beide Werte und einen nächsten Schritt.
 * Nie „Fälschung“, „Betrug“, „manipuliert“, „verdächtig“.
 */
final class RksvPruefer
{
    public const AUSFALL_TEXT = 'Sicherheitseinrichtung ausgefallen';

    public const TRAINING = 'TRA';

    public const STORNO = 'STO';

    public function __construct(private readonly RksvParser $parser = new RksvParser) {}

    /**
     * Prüft einen QR-Rohtext. Liefert immer eine Ergebnisliste, auch wenn der Code nicht zerlegbar ist.
     *
     * @return array{beleg: ?RksvBeleg, ergebnisse: list<PruefErgebnis>}
     */
    public function pruefe(string $qrText, ?GedruckteWerte $gedruckt = null, ?DateTimeImmutable $eingang = null): array
    {
        try {
            $beleg = $this->parser->parse($qrText);
        } catch (RksvParseFehler $e) {
            return ['beleg' => null, 'ergebnisse' => [new PruefErgebnis(
                'AT-QR-02',
                Stufe::Widerspruch,
                'Der Kassen-QR-Code entspricht nicht dem österreichischen RKSV-Format: '.$e->getMessage().' Bitte Original-Beleg anfordern.',
                ['qr' => $qrText],
            )]];
        }

        $eingang ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Vienna'));

        $ergebnisse = [
            $this->algorithmus($beleg),
            $this->datum($beleg, $eingang),
            ...$this->laengenUndKennungen($beleg),
        ];

        if ($gedruckt !== null) {
            $ergebnisse = [...$ergebnisse, ...$this->vergleicheMitGedrucktem($beleg, $gedruckt)];
        }

        return ['beleg' => $beleg, 'ergebnisse' => $ergebnisse];
    }

    /** AT-QR-02: Algorithmuskennzeichen R1-AT<n>. */
    private function algorithmus(RksvBeleg $b): PruefErgebnis
    {
        if ($b->vda() === null) {
            return new PruefErgebnis('AT-QR-02', Stufe::Widerspruch,
                sprintf('Das Algorithmuskennzeichen „%s“ ist im RKSV-Format nicht vorgesehen (erwartet: R1-AT0, R1-AT1, …).', $b->algorithmus),
                ['kennzeichen' => $b->algorithmus]);
        }

        return PruefErgebnis::ok('AT-QR-02', 'Format des Kassen-QR-Codes ist korrekt.');
    }

    /** AT-QR-03: Datum/Uhrzeit gültig und nicht nach dem Eingang. */
    private function datum(RksvBeleg $b, DateTimeImmutable $eingang): PruefErgebnis
    {
        $tz = new DateTimeZone('Europe/Vienna');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $b->datumUhrzeit, $tz);
        $fehler = DateTimeImmutable::getLastErrors();

        if ($dt === false || ($fehler && ($fehler['warning_count'] > 0 || $fehler['error_count'] > 0))
            || $dt->format('Y-m-d\TH:i:s') !== $b->datumUhrzeit) {
            return new PruefErgebnis('AT-QR-03', Stufe::Widerspruch,
                sprintf('Datum/Uhrzeit im QR-Code („%s“) ist kein gültiger Zeitpunkt im Format JJJJ-MM-TTThh:mm:ss.', $b->datumUhrzeit),
                ['qr' => $b->datumUhrzeit]);
        }

        // 5 Minuten Toleranz für abweichende Uhren.
        if ($dt > $eingang->modify('+5 minutes')) {
            return new PruefErgebnis('AT-QR-03', Stufe::Widerspruch,
                sprintf('Der Beleg ist laut QR-Code am %s ausgestellt, also nach dem Eingang (%s). Einreicher um Erklärung bitten.',
                    $dt->format('d.m.Y H:i'), $eingang->setTimezone($tz)->format('d.m.Y H:i')),
                ['qr' => $b->datumUhrzeit, 'eingang' => $eingang->format(DATE_ATOM)]);
        }

        return PruefErgebnis::ok('AT-QR-03');
    }

    /**
     * AT-QR-08 Ausfall, AT-QR-09 Training/Storno, AT-QR-10 Startbeleg, AT-QR-11 Feldlängen.
     *
     * @return list<PruefErgebnis>
     */
    private function laengenUndKennungen(RksvBeleg $b): array
    {
        $out = [];

        // --- Signatur: Ausfall oder 64 Byte ES256 ---
        $sig = base64_decode($b->signatur, true);
        $ausfall = $sig === self::AUSFALL_TEXT;

        $out[] = $ausfall
            ? new PruefErgebnis('AT-QR-08', Stufe::Hinweis,
                'Laut QR-Code war die Sicherheitseinrichtung der Kasse bei diesem Beleg ausgefallen. Das ist zulässig, aber selten; bei Häufung im selben Lokal genauer ansehen.')
            : PruefErgebnis::ok('AT-QR-08');

        // --- Umsatzzähler: Training / Storno ---
        $zaehler = base64_decode($b->umsatzzaehler, true);
        $sonderwert = in_array($zaehler, [self::TRAINING, self::STORNO], true) ? $zaehler : null;

        $out[] = match ($sonderwert) {
            self::TRAINING => new PruefErgebnis('AT-QR-09', Stufe::Widerspruch,
                'Laut QR-Code ist das ein Trainingsbeleg der Kasse, also kein echter Verkauf. Ein Trainingsbeleg ist als Spesenbeleg nicht geeignet; Original-Rechnung anfordern.',
                ['umsatzzaehler' => 'TRA']),
            self::STORNO => new PruefErgebnis('AT-QR-09', Stufe::Widerspruch,
                'Laut QR-Code ist das ein Stornobeleg. Ein stornierter Verkauf ist als Spesenbeleg nicht geeignet; Einreicher um Erklärung bitten.',
                ['umsatzzaehler' => 'STO']),
            default => PruefErgebnis::ok('AT-QR-09'),
        };

        // --- Startbeleg: Sig-Voriger = erste 8 Byte von SHA-256(Kassen-ID) ---
        $startWert = base64_encode(substr(hash('sha256', $b->kassenId, true), 0, 8));
        $out[] = $b->sigVorigerBeleg === $startWert
            ? new PruefErgebnis('AT-QR-10', Stufe::Auffaellig,
                'Laut QR-Code ist das der Startbeleg der Kasse (erster Beleg nach Inbetriebnahme, Betrag üblicherweise 0). Als Spesenbeleg ungewöhnlich; Original-Rechnung anfordern.',
                ['kassen_id' => $b->kassenId])
            : PruefErgebnis::ok('AT-QR-10');

        // --- Feldlängen ---
        $probleme = [];
        if (! $ausfall && ($sig === false || strlen($sig) !== 64)) {
            $probleme[] = sprintf('Signatur hat %s statt 64 Byte', $sig === false ? 'kein gültiges BASE64' : strlen($sig));
        }
        $voriger = base64_decode($b->sigVorigerBeleg, true);
        if ($voriger === false || strlen($voriger) !== 8) {
            $probleme[] = sprintf('Verkettungswert hat %s statt 8 Byte', $voriger === false ? 'kein gültiges BASE64' : strlen($voriger));
        }
        if ($sonderwert === null && ($zaehler === false || strlen($zaehler) < 5 || strlen($zaehler) > 16)) {
            $probleme[] = sprintf('Umsatzzähler hat %s statt 5 bis 16 Byte', $zaehler === false ? 'kein gültiges BASE64' : strlen($zaehler));
        }
        if (trim($b->kassenId) === '' || trim($b->belegnummer) === '' || trim($b->zertifikatSn) === '') {
            $probleme[] = 'Kassen-ID, Belegnummer oder Zertifikat-Seriennummer ist leer';
        }

        $out[] = $probleme === []
            ? PruefErgebnis::ok('AT-QR-11')
            : new PruefErgebnis('AT-QR-11', Stufe::Widerspruch,
                'Der Kassen-QR-Code ist technisch unvollständig: '.implode('; ', $probleme).'. Eine Registrierkasse erzeugt solche Werte nicht; Original-Beleg anfordern.',
                ['probleme' => $probleme]);

        return $out;
    }

    /**
     * AT-QR-04 bis AT-QR-07: Abgleich QR ↔ gedruckt. Bei niedriger Lesesicherheit nur Hinweis.
     *
     * @return list<PruefErgebnis>
     */
    private function vergleicheMitGedrucktem(RksvBeleg $b, GedruckteWerte $g): array
    {
        $out = [];

        // AT-QR-04: Summe
        if ($g->gesamtCent === null) {
            $out[] = new PruefErgebnis('AT-QR-04', Stufe::NichtPruefbar, 'Gesamtbetrag auf dem Beleg nicht gelesen.');
        } elseif ($g->gesamtCent !== $b->summeCent()) {
            $out[] = $this->abweichung('AT-QR-04', $g->sicher('gesamt'),
                sprintf('Der Betrag im Kassen-QR-Code (%s) weicht vom gedruckten Gesamtbetrag (%s) ab.', self::eur($b->summeCent()), self::eur($g->gesamtCent)),
                ['qr' => $b->summeCent(), 'gedruckt' => $g->gesamtCent]);
        } else {
            $out[] = PruefErgebnis::ok('AT-QR-04', 'Gesamtbetrag stimmt mit dem QR-Code überein.');
        }

        // AT-QR-05: Beträge je Steuersatz
        if ($g->betraegeJeSatzCent === null) {
            $out[] = new PruefErgebnis('AT-QR-05', Stufe::NichtPruefbar, 'Beträge je Steuersatz auf dem Beleg nicht gelesen.');
        } else {
            $diff = [];
            foreach (RksvBeleg::SATZ_FELDER as $feld) {
                $qr = $b->betraegeCent[$feld];
                $gd = $g->betraegeJeSatzCent[$feld] ?? 0;
                if ($qr !== $gd) {
                    $diff[] = sprintf('%s: QR %s, gedruckt %s', self::satzName($feld), self::eur($qr), self::eur($gd));
                }
            }
            $out[] = $diff === []
                ? PruefErgebnis::ok('AT-QR-05')
                : $this->abweichung('AT-QR-05', $g->sicher('betraege'),
                    'Die Beträge je Steuersatz im Kassen-QR-Code weichen vom Gedruckten ab ('.implode('; ', $diff).').',
                    ['abweichungen' => $diff]);
        }

        // AT-QR-06: Datum/Uhrzeit (Minutengenau; Sekunden nur, wenn gedruckt)
        if ($g->datumUhrzeit === null) {
            $out[] = new PruefErgebnis('AT-QR-06', Stufe::NichtPruefbar, 'Datum/Uhrzeit auf dem Beleg nicht gelesen.');
        } else {
            $qr = str_replace('T', ' ', $b->datumUhrzeit);
            $gd = trim($g->datumUhrzeit);
            $vergleich = strlen($gd) === 16 ? substr($qr, 0, 16) : $qr; // "JJJJ-MM-TT hh:mm" = 16 Zeichen
            $out[] = $vergleich === $gd
                ? PruefErgebnis::ok('AT-QR-06')
                : $this->abweichung('AT-QR-06', $g->sicher('datum_uhrzeit'),
                    sprintf('Datum/Uhrzeit im Kassen-QR-Code (%s) weicht vom gedruckten Wert (%s) ab.', $vergleich, $gd),
                    ['qr' => $vergleich, 'gedruckt' => $gd]);
        }

        // AT-QR-07: Kassen-ID
        if ($g->kassenId === null) {
            $out[] = new PruefErgebnis('AT-QR-07', Stufe::NichtPruefbar, 'Kassen-ID auf dem Beleg nicht gelesen.');
        } else {
            $out[] = $this->normId($g->kassenId) === $this->normId($b->kassenId)
                ? PruefErgebnis::ok('AT-QR-07')
                : $this->abweichung('AT-QR-07', $g->sicher('kassen_id'),
                    sprintf('Die Kassen-ID im QR-Code („%s“) weicht von der gedruckten Kassen-ID („%s“) ab.', $b->kassenId, $g->kassenId),
                    ['qr' => $b->kassenId, 'gedruckt' => $g->kassenId]);
        }

        return $out;
    }

    private function abweichung(string $code, bool $sicherGelesen, string $text, array $werte): PruefErgebnis
    {
        return $sicherGelesen
            ? new PruefErgebnis($code, Stufe::Widerspruch, $text.' Original-Beleg anfordern oder Einreicher um Erklärung bitten.', $werte)
            : new PruefErgebnis($code, Stufe::Hinweis, $text.' Der gedruckte Wert wurde unsicher gelesen; bitte am Bild prüfen.', $werte);
    }

    private function normId(string $id): string
    {
        return mb_strtolower(preg_replace('/\s+/', '', $id));
    }

    public static function eur(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.').' €';
    }

    private static function satzName(string $feld): string
    {
        return match ($feld) {
            'normal' => '20 %',
            'ermaessigt1' => '10 %',
            'ermaessigt2' => '13 %',
            'null' => '0 %',
            'besonders' => 'besonderer Satz',
        };
    }
}
